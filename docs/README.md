# Instalação do Monitor em produção

Guias passo a passo para instalar cada componente. Siga na ordem: cada etapa usa o que a anterior criou.

| # | Guia | Onde | Quem faz |
|---|---|---|---|
| 1 | [ClickHouse](1-clickhouse.md) | uma VM Linux central | infraestrutura |
| 2 | [Agente PHP](2-agente.md) | cada servidor web (Windows/IIS) e cada site | infraestrutura + time do site |
| 3 | [Coletor](3-coletor.md) | cada servidor web (serviço do Windows) | infraestrutura |
| 4 | [Painel](4-painel.md) | um servidor Windows com IIS | infraestrutura |
| 5 | [Operação](5-operacao.md) | conferência ponta a ponta, atualização, problemas comuns | todos |

## Visão geral

```
 Servidor web 1 (Windows/IIS)            Servidor web N (Windows/IIS)
 ┌───────────────────────────────┐       ┌───────────────────────────────┐
 │ IIS + PHP + agente            │       │ IIS + PHP + agente            │
 │   grava D:\monitor\data\      │       │   grava D:\monitor\data\      │
 │         <site>\traces-*.jsonl │       │         <site>\traces-*.jsonl │
 │ Serviço MonitorCollector      │       │ Serviço MonitorCollector      │
 │   lê os arquivos, envia e     │       │   lê os arquivos, envia e     │
 │   apaga o que foi conferido   │       │   apaga o que foi conferido   │
 └──────────────┬────────────────┘       └──────────────┬────────────────┘
                │ HTTPS 8443 (INSERT)                    │
                └──────────────►  ClickHouse  ◄──────────┘
                                 (VM Linux)
                                     ▲ HTTPS 8443 (SELECT, só leitura)
                                     │
                          Painel (IIS) ──► /_monitor/health.php de cada site (uptime)
```

- A requisição PHP só grava um arquivo local. Se o coletor ou o ClickHouse caírem, os sites não são afetados:
  os arquivos acumulam em `D:\monitor\data` e são enviados quando tudo voltar.
- Um coletor por **servidor**, não por site: ele lê todas as pastas `data\<site>\`.
- Os servidores web só abrem conexões **de saída** para o ClickHouse. Nada precisa entrar neles para o monitor.

## Pastas em cada servidor web

```
D:\monitor\agent\       agente PHP (lido pelo IIS)
D:\monitor\data\        traces gravados pelo agente; estado do coletor (collector-state.json) e rejected\
D:\monitor\collector\   coletor (Monitor.Collector.exe + appsettings.json + appsettings.Local.json)
```

## Rede

| Origem | Destino | Porta | Para quê |
|---|---|---|---|
| Servidores web (coletor) | ClickHouse | TCP 8443 (HTTPS) | enviar traces e conferir antes de apagar |
| Servidor do painel | ClickHouse | TCP 8443 (HTTPS) | consultas do painel |
| Servidor do painel | URL pública de cada site | 443 | uptime e `/_monitor/health.php` |
| Usuários | Servidor do painel | 443 | abrir o painel |
| Administradores | ClickHouse | TCP 22 (SSH) | administração |

As portas 8123 (HTTP sem TLS) e 9000 (protocolo nativo) do ClickHouse não devem ser abertas na rede.

## Contas e senhas

| Conta | Onde | Permissão |
|---|---|---|
| `default` (ClickHouse) | só de dentro da VM | administração, criação de usuários e schema |
| `monitor_collector` (ClickHouse) | coletores | `INSERT` em `requests`/`calls`, `SELECT` em `requests` |
| `monitor_panel` (ClickHouse) | painel | `SELECT` em `monitor.*` |
| `NT SERVICE\MonitorCollector` (Windows) | cada servidor web | conta virtual do serviço; ler `collector\`, modificar `data\` |
| `IIS AppPool\<pool do site>` (Windows) | cada servidor web | modificar `data\` (o agente grava ali) |
| `IIS AppPool\MonitorPanel` (Windows) | servidor do painel | ler a pasta do painel |
| Token do agente | `config.local.php` + painel | chamar `/_monitor/health.php` |

Guarde as senhas no cofre de senhas da equipe. Os arquivos que as contêm (`appsettings.Local.json` e `config.local.php`)
ficam fora do git e com acesso restrito (ver cada guia).

## O que foi testado

Testado com ClickHouse 25.8 em container, usando os mesmos arquivos de configuração do guia 1:
- `default` restrito ao localhost e HTTPS na 8443;
- usuários `monitor_collector` e `monitor_panel` com as permissões acima;
- coletor publicado self-contained, rodando de outra pasta, enviando por HTTPS, conferindo e apagando o arquivo;
- painel lendo com o usuário só de leitura.

**Ainda não testado:**
- instalação como serviço do Windows com a conta virtual;
- painel hospedado no IIS;
- instalação do ClickHouse por pacote numa VM;
- certificado da CA interna.

Faça a primeira instalação num servidor de homologação.
