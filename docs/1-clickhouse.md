# 1. ClickHouse (VM Linux central)

O ClickHouse guarda os traces de todos os servidores. Ele **não roda nativamente no Windows**, por isso fica numa
VM Linux. O exemplo usa Oracle Linux 9 na OCI, na mesma região dos servidores web; para Ubuntu, veja o fim do passo 2.
Versão testada: **25.8 (LTS)**.

## 1. Criar a VM

- **Sistema:** Oracle Linux 9, na mesma VCN dos servidores web (ou com rota para ela).
- **Tamanho inicial:** 2 OCPU e 16 GB de RAM.
- **Disco:** um *block volume* separado, montado em `/var/lib/clickhouse`, onde ficam os dados. Assim dá para
  aumentar o disco sem mexer no boot volume. Monte o volume **antes** de instalar.
- **Rede:** sem IP público. Na Security List ou NSG da subnet, libere entrada TCP 8443 só das subnets dos servidores
  web e do painel, e TCP 22 só da rede de administração.
- **DNS interno:** registre um nome, por exemplo `clickhouse.interno`. O certificado do passo 4 usa esse nome.

## 2. Instalar

```bash
sudo dnf install -y yum-utils
sudo yum-config-manager --add-repo https://packages.clickhouse.com/rpm/clickhouse.repo
sudo dnf install -y clickhouse-server clickhouse-client
```

Para fixar a versão, veja as disponíveis com `dnf list --showduplicates clickhouse-server` e instale a 25.8 exata
(`sudo dnf install -y clickhouse-server-<versão> clickhouse-client-<versão>`).

Se o instalador criou `/etc/clickhouse-server/users.d/default-password.xml`, apague esse arquivo: a senha do
`default` é definida no passo 3, e duas definições de senha impedem o servidor de subir.

**Ubuntu**, em vez do bloco acima:

```bash
sudo apt-get install -y apt-transport-https ca-certificates curl gnupg
curl -fsSL 'https://packages.clickhouse.com/rpm/lts/repodata/repomd.xml.key' | sudo gpg --dearmor -o /usr/share/keyrings/clickhouse-keyring.gpg
echo "deb [signed-by=/usr/share/keyrings/clickhouse-keyring.gpg arch=$(dpkg --print-architecture)] https://packages.clickhouse.com/deb stable main" | sudo tee /etc/apt/sources.list.d/clickhouse.list
sudo apt-get update && sudo apt-get install -y clickhouse-server clickhouse-client
```

## 3. Restringir o usuário `default`

O `default` vira a conta de administração, com senha e acesso **só de dentro da VM**. Os coletores e o painel
usam os usuários próprios do passo 6.

Gere o hash da senha (ela fica no cofre da equipe, não neste arquivo):

```bash
read -rs -p "Senha do default: " P; echo; echo -n "$P" | sha256sum | cut -d' ' -f1; unset P
```

`/etc/clickhouse-server/users.d/default.xml`:

```xml
<clickhouse>
  <users>
    <default>
      <password remove="1"/>
      <password_sha256_hex>COLE_O_HASH_AQUI</password_sha256_hex>
      <access_management>1</access_management>
      <networks replace="replace">
        <ip>::1</ip>
        <ip>127.0.0.1</ip>
      </networks>
    </default>
  </users>
</clickhouse>
```

- `access_management` permite que o `default` crie os outros usuários por SQL.
- `networks replace="replace"` troca o padrão, que aceita qualquer IP, por apenas o localhost.

## 4. HTTPS na porta 8443

Coloque o certificado e a chave em `/etc/clickhouse-server/certs/`. O certificado precisa ter o nome do passo 1
(`clickhouse.interno`) no *Subject Alternative Name*. A melhor opção é um certificado emitido pela CA interna,
porque os servidores Windows já confiam nela.

Sem CA interna, gere um autoassinado. Depois cada servidor Windows precisa importar `server.crt`
(guia do [coletor](3-coletor.md#4-confiar-no-certificado-do-clickhouse)):

```bash
sudo mkdir -p /etc/clickhouse-server/certs && cd /etc/clickhouse-server/certs
sudo openssl req -x509 -newkey rsa:2048 -nodes -days 825 \
  -subj "/CN=clickhouse.interno" -addext "subjectAltName=DNS:clickhouse.interno" \
  -keyout server.key -out server.crt
sudo chown -R clickhouse:clickhouse /etc/clickhouse-server/certs && sudo chmod 600 server.key
```

`/etc/clickhouse-server/config.d/rede.xml`:

```xml
<clickhouse>
  <listen_host>0.0.0.0</listen_host>
  <https_port>8443</https_port>
  <openSSL>
    <server>
      <certificateFile>/etc/clickhouse-server/certs/server.crt</certificateFile>
      <privateKeyFile>/etc/clickhouse-server/certs/server.key</privateKeyFile>
      <verificationMode>none</verificationMode>
      <disableProtocols>sslv2,sslv3,tlsv1,tlsv1_1</disableProtocols>
      <preferServerCiphers>true</preferServerCiphers>
    </server>
  </openSSL>
</clickhouse>
```

`listen_host 0.0.0.0` abre **todas** as portas do ClickHouse na rede (8123, 9000, 8443). O firewall do passo 5 deixa
passar só a 8443.

## 5. Firewall da VM e início

```bash
sudo firewall-cmd --permanent --add-port=8443/tcp
sudo firewall-cmd --reload
sudo systemctl enable --now clickhouse-server
sudo systemctl status clickhouse-server --no-pager
```

Teste. Use `--password` sem valor para o cliente perguntar a senha e ela não ficar no histórico do shell:

```bash
clickhouse-client --password -q "SELECT version()"
curl -s --cacert /etc/clickhouse-server/certs/server.crt https://clickhouse.interno:8443/ping   # "Ok."
```

## 6. Banco, tabelas e usuários

Crie o schema com o `schema.sql` do repositório (`collector/schema.sql`), copiado para a VM. Os valores são os
padrões do coletor: 30 dias de detalhe e 400 dias do agregado por minuto.

```bash
sed -e 's/{db}/monitor/g' -e 's/{ttl_days}/30/g' -e 's/{rollup_ttl_days}/400/g' schema.sql > schema-monitor.sql
clickhouse-client --password --queries-file schema-monitor.sql
```

Usuários. Troque as senhas e as faixas de IP (`HOST IP`) pelas subnets reais:

```sql
-- clickhouse-client --password
CREATE USER monitor_collector IDENTIFIED WITH sha256_password BY 'SENHA_DO_COLETOR'
    HOST IP '10.0.1.0/24';                 -- subnet dos servidores web
GRANT INSERT ON monitor.requests TO monitor_collector;
GRANT INSERT ON monitor.calls    TO monitor_collector;
GRANT SELECT ON monitor.requests TO monitor_collector;

CREATE USER monitor_panel IDENTIFIED WITH sha256_password BY 'SENHA_DO_PAINEL'
    HOST IP '10.0.2.10/32';                -- servidor do painel
GRANT SELECT ON monitor.* TO monitor_panel;
```

O `SELECT` em `requests` para o coletor é necessário por dois motivos:
- a view materializada `routes_1m_mv` roda com as permissões de quem insere, e lê várias colunas de `requests`;
- antes de apagar um arquivo, o coletor confere quantas requisições dele chegaram.

Sem essa permissão, o INSERT em `requests` grava as linhas, mas o agregado `routes_1m` falha e fica sem elas.
Por isso o coletor usa `"CreateSchema": false` e não tem permissão de DDL.

## 7. Conferir

```sql
SHOW TABLES FROM monitor;              -- calls, requests, routes_1m, routes_1m_mv
SHOW GRANTS FOR monitor_collector;
SHOW GRANTS FOR monitor_panel;
```

De um servidor web (PowerShell), com o certificado já confiável (guia 3, passo 4):

```powershell
$cred = Get-Credential monitor_collector
Invoke-RestMethod "https://clickhouse.interno:8443/?query=SELECT%20currentUser()" -Headers @{
  'X-ClickHouse-User' = $cred.UserName; 'X-ClickHouse-Key' = $cred.GetNetworkCredential().Password }
```

## Mudar a retenção depois

```sql
ALTER TABLE monitor.requests  MODIFY TTL toDateTime(Timestamp) + toIntervalDay(60);
ALTER TABLE monitor.calls     MODIFY TTL toDateTime(Timestamp) + toIntervalDay(60);
ALTER TABLE monitor.routes_1m MODIFY TTL Minute + toIntervalDay(400);
```

## Espaço usado

```sql
SELECT table, formatReadableSize(sum(bytes_on_disk)) AS disco, sum(rows) AS linhas
FROM system.parts WHERE database = 'monitor' AND active GROUP BY table;
```

## Backup

Os dados são telemetria com retenção curta, então um backup é opcional. Se quiser um, o ClickHouse grava backups em
storage compatível com S3, como o Object Storage da OCI, com `BACKUP DATABASE monitor TO S3(...)`. Isso não foi
testado neste projeto.
