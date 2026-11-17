# Monitor

Rastreia cada requisição de sites PHP (rota, tempo, CPU, memória, servidor) e as chamadas que ela faz a outros
sistemas (SQL, HTTP, Redis, sessão), em formato **OpenTelemetry**, sem editar o código dos sites.
Foco inicial: sites Zend Framework 2 + Doctrine em PHP 7.0.33 no IIS.

| Pasta | O que é |
|---|---|
| `agent/` | **o agente PHP** (vai para cada servidor) |
| `agent/zf2/` | integração Zend Framework 2: rota, erros, Doctrine, cache, `Zend\Http\Client`, shims |
| `collector/` | **coletor** em C# (.NET 10, serviço do Windows): lê `data/` e envia ao ClickHouse |
| `panel/` | painel em C# (.NET 10): uptime, health, servidores, rotas, chamadas filhas, sistemas externos, acessos |
| `site/`, `servicos/` | site de protótipo e "sistema externo" simulado, para testar sem um site real |
| `data/` | traces gravados (`data/<site>/traces-AAAAMMDD-HH.jsonl`) — gerado, fora do git |

## Rodar o protótipo

```powershell
.\start.ps1
```

Site: http://localhost:8080 · Serviços simulados: http://127.0.0.1:8070 · Painel: http://localhost:5050

## Instalar num servidor (sites ZF2)

1. Copie a pasta `agent/` para o servidor, ex.: `D:\monitor\agent` (e o painel em `D:\monitor\panel`).
   Não é preciso instalar nada no IIS. O pool de aplicação precisa poder gravar em `data_dir`
   (padrão: a pasta `data` ao lado do agente, ex.: `D:\monitor\data`).
2. Opcional: `agent/config.local.php` com o que muda neste servidor (fica fora do git):
   ```php
   <?php return array('server_name' => 'WEB-01', 'sample_rate' => 0.05, 'token' => 'troque-isto');
   ```
3. Em cada site, três arquivos **iguais para todos os sites ZF2**:

   | Arquivo no site | Conteúdo |
   |---|---|
   | `config\monitor-loader.php` | cópia de `agent/loader.php` |
   | `public\.user.ini` | `auto_prepend_file = "D:\Sites\<site>\config\monitor-loader.php"` |
   | `config\autoload\monitor.local.php` | cópia de `agent/zf2/site/monitor.local.php` |

   **Nunca aponte o `.user.ini` direto para o agente fora do site**: se o arquivo não existir, o PHP dá erro
   fatal em todas as páginas. O loader só carrega o agente se ele existir (procura em `MONITOR_HOME`,
   `monitor.home` do php.ini, na própria pasta, em `D:\monitor\agent` e em `C:\monitor\agent`); sem ele o site segue normal.
   O `monitor.local.php` também não faz nada sem o agente.

4. No painel, um alvo por site (em `panel/appsettings.Local.json`, fora do git; os alvos se mesclam por posição
   com os do `appsettings.json`, então comece com `{}` para manter o site de protótipo), com um nó por servidor (endereço interno de cada um,
   não o balanceador — o log é local de cada servidor):
   ```json
   { "Id": "loja", "Name": "Loja", "Url": "https://loja.exemplo.com.br/", "Token": "troque-isto",
     "HealthUrl": "https://loja.exemplo.com.br/_monitor/health.php",
     "Nodes": [
       { "Name": "WEB-01", "TracesUrl": "https://10.0.0.11/_monitor/traces.php", "HostHeader": "loja.exemplo.com.br" },
       { "Name": "WEB-02", "TracesUrl": "https://10.0.0.12/_monitor/traces.php", "HostHeader": "loja.exemplo.com.br" }
     ] }
   ```

O PHP relê o `.user.ini` a cada 5 minutos (ou recicle o pool do IIS).

## O que o agente mede sem editar o site

| Item | Como |
|---|---|
| Requisição: rota, método, status, tempo, CPU (usuário/sistema), memória, servidor | `auto_prepend_file` + `register_shutdown_function` |
| Nome da rota (`Controller::action`) e exceções tratadas pelo ZF2 | delegator do serviço `Application` |
| SQL de **todas** as conexões do Doctrine (descobertas na config) | um `SQLLogger` por conexão (delegator) |
| Cache Zend\Cache/Redis (`cache` + `caches` da config) e `Common\Redis` | eventos do Zend\Cache / proxy |
| `Zend\Http\Client` (Socket e Curl; arquivo achado pelo próprio Composer) | subclasse carregada antes do Composer |
| `curl_exec`, `curl_setopt`, `session_start` | shims de função por namespace (gerados do código do site) |
| `file_get_contents`/`fopen` em URL | wrapper `http(s)://` (`auto_http`) |

Fora: SOAP via classe global `SoapClient`, chamadas com barra (`\curl_exec`) e código sem namespace.
Para esses, há substitutos que exigem editar uma linha: `MonitorPDO`, `MonitorMysqli`, `monitor_curl_exec`,
`MonitorSoapClient`, `Monitor::wrap(...)`, `Monitor::span(...)` (ver `agent/src/instrument.php`).

Arquivos gerados por site em `data/<site>/generated/` (apague para regenerar): `shims.php` (refeito a cada
`shims_ttl_hours`) e a cópia renomeada do `Zend\Http\Client`.

## Formato: OpenTelemetry (OTLP/JSON)

Uma linha por requisição, no formato `ExportTraceServiceRequest`:

- **resource**: `service.name` (site, pelo Host), `host.name` (servidor: `MONITOR_SERVER_NAME` ou `COMPUTERNAME`).
  O `X-Servername` do IIS é posto na resposta depois do PHP, então não dá para lê-lo; confira que bate com o nome da máquina.
- **span SERVER**: `http.route`, `http.request.method`, `url.path`, `http.response.status_code` + `monitor.cpu_ms`,
  `monitor.memory.peak`, `monitor.ext_ms`, `monitor.ext_calls`, `monitor.detailed`.
- **spans CLIENT** (filhos): semânticos (`db.system`, `db.statement`, `server.address`, `url.full`,
  `http.response.status_code`) + `monitor.type`/`monitor.target`/`monitor.op`.
- **Trace Context (W3C)**: lê `traceparent` na entrada e envia nas chamadas `Zend\Http\Client` e cURL (quando o site
  define os cabeçalhos com `curl_setopt`). Requisições entre sites monitorados ficam no mesmo trace.

O SQL vai normalizado (valores literais viram `?`), URLs sem query string, DSNs sem usuário/senha; textos ISO-8859-1
são convertidos para UTF-8.

## Volume e custo

- Arquivos estáticos que chegam ao PHP (404 reescrito para `index.php`) são ignorados.
- Toda requisição grava o resumo; as chamadas filhas só quando a requisição é lenta (`slow_ms`), deu erro, ou
  cai na amostra (`sample_rate`). Arquivos por hora, apagados depois de `retention_hours`.
- Medido no PHP 7.0.33: ~3 ms e ~90 KB por requisição (quase tudo é carregar o agente), ~2 KB de log com detalhe.

## Coletor → ClickHouse

`collector/` é um serviço do Windows instalado **em cada servidor**, ao lado do agente. Ele acompanha
`data/<site>/traces-*.jsonl` e insere as linhas novas no ClickHouse pela interface HTTP (gzip, `JSONEachRow`).
A requisição PHP não muda nada: o agente continua só gravando o arquivo.

- **Sem perda**: o offset de cada arquivo (`data/collector-state.json`) só avança depois do INSERT confirmado. Com o
  ClickHouse fora, os próprios arquivos são o buffer; o coletor tenta de novo com espera crescente até 5 min.
- **Apaga o que já foi carregado** (`DeleteShipped`, padrão ligado). Quem aplica o `retention_hours` do agente é o
  `/_monitor/traces.php`, que só o painel em modo memória chama; no modo ClickHouse, sem isto, `data/` só cresce.
  Um arquivo é apagado quando as três condições valem:
  1. foi todo enviado (offset = tamanho);
  2. não é gravado há `DeleteAfterMinutes` (70, mínimo 61): a hora dele fechou e o agente não escreve mais nele;
  3. **conferência no ClickHouse**: `requests` tem pelo menos as requisições aceitas daquele arquivo (contagem por
     site, servidor e faixa de horário guardada no estado).

  Se a conferência falhar, o arquivo fica e o coletor avisa e confere de novo em 10 min. Arquivos sem contagem
  (estado de versão anterior ou `StartAtEnd`) só são apagados depois de `UnverifiedRetentionHours` (48). Lotes recusados
  ficam em `data/rejected/` (não são apagados). Cada exclusão vai para o log: `... apagado: N requisição(ões) conferidas no ClickHouse`.
- **Sem duplicar**: cada lote vai com `insert_deduplication_token` (arquivo + trecho), então reenviar o mesmo trecho
  (queda no meio, estado apagado) é descartado pelo ClickHouse.
- Linhas incompletas (ainda sendo escritas) esperam o próximo ciclo; linhas inválidas são ignoradas com aviso; um lote
  que o ClickHouse recusa (4xx) vai para `data/rejected/` em vez de travar o envio.

Tabelas (criadas pelo coletor ao iniciar, `collector/schema.sql`):

| Tabela | Conteúdo |
|---|---|
| `requests` | uma linha por requisição: site, servidor, rota, status, tempo, CPU, memória, chamadas externas, erro, IP, UA + todos os atributos em `Attributes` |
| `calls` | uma linha por chamada filha (SQL, HTTP, Redis, sessão) com a rota da requisição repetida, tempos de HTTP (DNS, connect, TLS, TTFB) |
| `routes_1m` | agregado por minuto (view materializada): requisições, erros, lentas, soma/máx de tempo, p50/p95/p99 |

Retenção: `TtlDays` (30) para `requests`/`calls`, `RollupTtlDays` (400) para `routes_1m`.

### Instalar

```powershell
dotnet publish collector -c Release -o D:\monitor\collector
```

`D:\monitor\collector\appsettings.Local.json` (fora do git):

```json
{ "Collector": { "DataDir": "..\\data",
  "ClickHouse": { "Url": "https://clickhouse.interno:8443", "Database": "monitor", "User": "monitor", "Password": "..." } } }
```

A senha também pode vir da variável de ambiente `MONITOR_CLICKHOUSE_PASSWORD`. Se o usuário não puder criar tabelas,
rode `schema.sql` à mão (troque `{db}`, `{ttl_days}`, `{rollup_ttl_days}`) e use `"CreateSchema": false`.
Para não enviar o histórico já gravado na primeira execução: `"StartAtEnd": true`.

```powershell
sc.exe create MonitorCollector binPath= "D:\monitor\collector\Monitor.Collector.exe" start= delayed-auto
sc.exe failure MonitorCollector reset= 86400 actions= restart/5000/restart/5000/restart/60000
sc.exe start MonitorCollector
```

A conta do serviço precisa ler e gravar em `data\` (estado e `rejected\`). Avisos e erros vão para o Log de Eventos.

### Testar localmente

```powershell
docker run -d --name monitor-clickhouse -p 8123:8123 -e CLICKHOUSE_PASSWORD=devpass clickhouse/clickhouse-server:25.8
$env:MONITOR_CLICKHOUSE_PASSWORD = 'devpass'; dotnet run --project collector
```

```sql
SELECT Route, sum(Requests) AS n, round(sum(DurationSum) / n, 1) AS media_ms,
       round(quantilesTDigestMerge(0.5, 0.95, 0.99)(Quantiles)[2], 1) AS p95_ms
FROM monitor.routes_1m WHERE Minute > now() - INTERVAL 1 DAY GROUP BY Route ORDER BY n DESC;
```

## Painel

- Sites à esquerda; um por vez. Servidores do site (clique filtra os acessos).
- Rotas: clicar abre as chamadas filhas (SQL, HTTP, Redis, sessão) com média, máximo, total e % do tempo da rota.
- Acessos: clique abre a linha do tempo da requisição; chamadas entre sistemas mostram o trace completo.
- Tabelas ordenáveis, 10/20/50/100/200 linhas.
- Uptime e health: o painel lê `/_monitor/health.php` de cada site (10 s).
- Rotas, servidores, sistemas externos e acessos vêm de uma de duas fontes:

  | Fonte | Quando | Como |
  |---|---|---|
  | **Memória** (padrão) | sem `Monitor:ClickHouse:Url` | o painel puxa `/_monitor/traces.php` de cada servidor (2 s, incremental por cursor); mostra tudo desde que o painel subiu |
  | **ClickHouse** | com `Monitor:ClickHouse:Url` | lê as tabelas do coletor; seletor de **Período** no topo (15 min a 30 dias); o painel não precisa alcançar os servidores (`TracesUrl`/`Nodes` são ignorados) |

  No ClickHouse, cada alvo é achado pelo host da `Url` (é o `service.name` gravado pelo agente); se for diferente,
  informe `"Site": "loja.exemplo.com.br"` no alvo. Busca por trace ignora o período.

  ```json
  { "Monitor": { "ClickHouse": { "Url": "http://clickhouse.interno:8123", "Database": "monitor", "User": "painel",
      "Password": "...", "DefaultWindowMinutes": 60, "MaxWindowMinutes": 43200 } } }
  ```

  O painel só lê (`readonly=2`): basta um usuário com `SELECT` em `monitor.*`. A senha também pode vir de
  `MONITOR_CLICKHOUSE_PASSWORD`. Para testar local: perfil `clickhouse` (`dotnet run --project panel --launch-profile clickhouse`,
  porta 5051, aponta para o container do exemplo acima). Com o ClickHouse fora, a API responde 503 e o topo da tela mostra o motivo.

## Próximos passos (fora deste teste)

- Alertas a partir do ClickHouse (erro/latência por rota em `routes_1m`) e gráficos de tendência por rota.
- Laravel/PHP 8: instrumentação oficial do OpenTelemetry para PHP 8 ou uma camada Laravel.
- Painel com autenticação.

## Simular falhas (protótipo)

`/demo/chaos.php?mode=ok|slow|error|down` ou os botões no painel. O pagamento simulado devolve 502 em ~15% das
chamadas e o estoque às vezes demora 3 s.
