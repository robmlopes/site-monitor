# 3. Coletor (serviço do Windows em cada servidor web)

O coletor é o serviço `MonitorCollector`. Ele lê `D:\monitor\data\<site>\traces-*.jsonl` e insere as linhas novas no ClickHouse.
Depois que um arquivo foi todo enviado e conferido no ClickHouse, o coletor o apaga.

Antes: ClickHouse pronto (guia 1) e agente gravando em `D:\monitor\data` (guia 2).

## 1. Gerar o pacote (uma vez por versão, na máquina de build)

Gere um pacote **self-contained**. Ele leva o runtime do .NET junto, então os servidores não precisam ter o .NET instalado.

```powershell
dotnet publish collector -c Release -r win-x64 --self-contained -o .\publish\collector
Compress-Archive .\publish\collector\* .\publish\monitor-collector.zip -Force
```

O pacote tem cerca de 80 MB. O `appsettings.Local.json` nunca entra nele, porque cada servidor tem o seu.

## 2. Copiar para o servidor

Em PowerShell como administrador, no servidor:

```powershell
New-Item -ItemType Directory -Force D:\monitor\collector | Out-Null
Expand-Archive .\monitor-collector.zip D:\monitor\collector -Force
```

## 3. Configuração do servidor (`appsettings.Local.json`)

Crie `D:\monitor\collector\appsettings.Local.json`:

```json
{
  "Collector": {
    "DataDir": "..\\data",
    "StartAtEnd": false,
    "ClickHouse": {
      "Url": "https://clickhouse.interno:8443",
      "Database": "monitor",
      "User": "monitor_collector",
      "Password": "SENHA_DO_COLETOR",
      "CreateSchema": false
    }
  }
}
```

| Chave | Padrão | Observação |
|---|---|---|
| `DataDir` | `..\data` | relativo à pasta do coletor, ou seja, `D:\monitor\data`. Tem que ser o `data_dir` do agente |
| `StartAtEnd` | `false` | `true` ignora o que já estava gravado e envia só daqui para frente. Use quando a primeira instalação encontra muito histórico. Esses arquivos antigos só são apagados depois de `UnverifiedRetentionHours` |
| `CreateSchema` | `true` | use **`false`**: o schema é criado no guia 1, e o usuário do coletor não tem permissão de DDL |
| `DeleteShipped` | `true` | apaga o arquivo depois de enviado e conferido. Desligado, `data\` só cresce |
| `DeleteAfterMinutes` | `70` | só apaga arquivos sem gravação há esse tempo (mínimo 61), quando a hora do arquivo já fechou |
| `UnverifiedRetentionHours` | `48` | prazo para apagar arquivos sem contagem, como os vindos de `StartAtEnd` |
| `PollSeconds` | `2` | intervalo de leitura dos arquivos |
| `BatchMaxLines` / `BatchMaxBytes` | `5000` / 8 MB | tamanho máximo de cada INSERT |
| `ClickHouse:TimeoutSeconds` | `30` | |
| `ClickHouse:IgnoreTlsErrors` | `false` | não use em produção: confie no certificado (passo 4) |

Os padrões ficam em `appsettings.json`, que é substituído a cada atualização. Mude só o `appsettings.Local.json`.

A senha também pode vir da variável de ambiente `MONITOR_CLICKHOUSE_PASSWORD`, e ela tem prioridade sobre o arquivo.

## 4. Confiar no certificado do ClickHouse

- **Certificado da CA interna:** nada a fazer, porque o Windows já confia nela.
- **Certificado autoassinado** (guia 1, passo 4): copie o `server.crt` da VM e importe:

```powershell
Import-Certificate -FilePath .\clickhouse.crt -CertStoreLocation Cert:\LocalMachine\Root
```

Teste a conexão (o resultado deve ser `Ok.`):

```powershell
Invoke-RestMethod https://clickhouse.interno:8443/ping
```

## 5. Criar o serviço

O serviço roda com a **conta virtual** `NT SERVICE\MonitorCollector`. Ela é criada junto com o serviço e não tem senha.
Só recebe acesso ao que é dado abaixo. Não use LocalSystem.

```powershell
sc.exe create MonitorCollector binPath= "D:\monitor\collector\Monitor.Collector.exe" start= delayed-auto obj= "NT SERVICE\MonitorCollector" DisplayName= "Monitor - Coletor ClickHouse"
sc.exe description MonitorCollector "Envia os traces do agente PHP (D:\monitor\data) para o ClickHouse."
sc.exe failure MonitorCollector reset= 86400 actions= restart/5000/restart/5000/restart/60000
sc.exe failureflag MonitorCollector 1
```

- O espaço depois de `binPath=`, `start=` e `obj=` é obrigatório no `sc.exe`.
- A política de falha reinicia o serviço após 5 s, depois 5 s e depois 60 s, e zera a contagem a cada 24 h.
- `failureflag 1` também aplica a política quando o serviço para com código de erro, não só quando o processo cai.

O que o coletor faz em cada falha:

| Falha | Comportamento |
|---|---|
| ClickHouse fora, timeout, senha errada | tenta de novo com espera crescente, até 5 min. Os arquivos ficam como buffer |
| Arquivo sem permissão ou disco cheio | pula só aquele arquivo por 1 min e avisa. Os outros seguem |
| Linha com JSON inválido ou formato inesperado | ignora a linha (aviso com o trecho) e segue com o arquivo |
| Lote recusado pelo ClickHouse (4xx) | salva em `data\rejected\` e segue |
| `collector-state.json` corrompido | recomeça do início, sem duplicar (deduplicação do ClickHouse) |
| Erro inesperado no laço principal | registra no Log de Eventos e encerra com erro. O Windows reinicia o serviço pela política acima |

## 6. Permissões

As permissões vêm depois do passo 5, porque a conta virtual só existe depois que o serviço é criado.

```powershell
$svc = "NT SERVICE\MonitorCollector"
icacls D:\monitor\collector /grant "${svc}:(OI)(CI)RX"
icacls D:\monitor\data      /grant "${svc}:(OI)(CI)M"
icacls D:\monitor\collector\appsettings.Local.json /inheritance:r /grant:r "Administrators:F" "SYSTEM:F" "${svc}:R"
```

- **`data\`:** modificar. O coletor grava ali o `collector-state.json` e a pasta `rejected\`, e apaga os arquivos enviados.
  Os arquivos novos do agente herdam essa permissão da pasta.
- **`appsettings.Local.json`:** só administradores e o serviço leem, porque o arquivo tem a senha.

Fonte do Log de Eventos. Avisos e erros do serviço vão para o log **Application**. Criar a fonte exige administrador,
então crie agora, em **Windows PowerShell 5.1**:

```powershell
if (-not [System.Diagnostics.EventLog]::SourceExists("Monitor.Collector")) {
  New-EventLog -LogName Application -Source "Monitor.Collector" }
```

## 7. Iniciar e conferir

```powershell
sc.exe start MonitorCollector
Get-Service MonitorCollector
```

No primeiro minuto, confira três coisas:

1. **Estado criado e avançando:** `Get-Content D:\monitor\data\collector-state.json`. O `Offset` de cada arquivo
   deve chegar ao tamanho do arquivo.
2. **Dados no ClickHouse** (na VM, `clickhouse-client --password`):
   ```sql
   SELECT Server, Site, count(), max(Timestamp) FROM monitor.requests
   WHERE Timestamp > now() - INTERVAL 1 HOUR GROUP BY Server, Site;
   ```
3. **Sem erros no Log de Eventos:**
   ```powershell
   Get-EventLog Application -Source "Monitor.Collector" -Newest 20 -ErrorAction SilentlyContinue
   ```

Depois de cerca de 70 minutos, os arquivos da hora anterior somem de `D:\monitor\data\<site>\`. Cada exclusão
também vai para o log com o número de requisições conferidas.

Para ver o log completo, inclusive mensagens informativas, pare o serviço e rode o coletor no console:

```powershell
sc.exe stop MonitorCollector
D:\monitor\collector\Monitor.Collector.exe      # Ctrl+C para sair
sc.exe start MonitorCollector
```

## Atualizar

```powershell
sc.exe stop MonitorCollector
Expand-Archive .\monitor-collector.zip D:\monitor\collector -Force   # não toca no appsettings.Local.json
sc.exe start MonitorCollector
```

O estado fica em `data\`, então a atualização continua de onde parou. Nada é reenviado nem perdido.

## Remover

```powershell
sc.exe stop MonitorCollector
sc.exe delete MonitorCollector
Remove-Item -Recurse D:\monitor\collector
```

Depois de remover o coletor, nada mais apaga `D:\monitor\data`. Desinstale também o agente (guia 2), ou apague os
arquivos de outra forma.
