# Monitor

Rastreia cada requisição de sites PHP (rota, tempo, CPU, memória, servidor) e as chamadas que ela faz a outros
sistemas (SQL, HTTP, Redis, sessão), em formato **OpenTelemetry**, sem editar o código dos sites.
Foco inicial: sites Zend Framework 2 + Doctrine em PHP 7.0.33 no IIS.

| Pasta | O que é |
|---|---|
| `agent/` | **o agente PHP** (vai para cada servidor) |
| `agent/zf2/` | integração Zend Framework 2: rota, erros, Doctrine, cache, `Zend\Http\Client`, shims |
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

## Painel

- Sites à esquerda; um por vez. Servidores do site (clique filtra os acessos).
- Rotas: clicar abre as chamadas filhas (SQL, HTTP, Redis, sessão) com média, máximo, total e % do tempo da rota.
- Acessos: clique abre a linha do tempo da requisição; chamadas entre sistemas mostram o trace completo.
- Tabelas ordenáveis, 10/20/50/100/200 linhas.
- Endpoints lidos: `/_monitor/health.php` (10 s) e `/_monitor/traces.php` (2 s, incremental por cursor).

## Próximos passos (fora deste teste)

- Coletor OpenTelemetry em cada servidor lendo `data/` e enviando para Loki/Tempo ou outro backend OpenTelemetry
  (sem custo na requisição e sem o painel precisar alcançar os servidores).
- Laravel/PHP 8: instrumentação oficial do OpenTelemetry para PHP 8 ou uma camada Laravel.
- Painel com armazenamento, autenticação e alertas.

## Simular falhas (protótipo)

`/demo/chaos.php?mode=ok|slow|error|down` ou os botões no painel. O pagamento simulado devolve 502 em ~15% das
chamadas e o estoque às vezes demora 3 s.
