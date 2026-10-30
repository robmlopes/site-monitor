# Monitor (protótipo)

- `site/`: site de exemplo em PHP 7.0.33 com várias rotas (produtos, busca, contato POST, checkout, relatório
  pesado, API JSON, página lenta, erro 500, 404) e a página `/testes.php`, que gera tráfego automático.
  Usa um banco SQLite (`site/data/`, criado na primeira requisição) e chama os serviços externos simulados.
- `servicos/`: "outro sistema" simulado (frete, estoque, cotação, pagamento) com latência variável e falhas
- `site/monitor/`: o que vai para o seu site
  - `agent.php`: registra cada requisição em `monitor/data/requests.log`: rota, método, status, tempo,
    CPU (usuário + sistema), memória e as chamadas a outros sistemas
  - `instrument.php`: mede banco, HTTP, SOAP, Redis etc. (ver abaixo)
  - `requests.php`: entrega esse log ao painel de forma incremental
  - `health.php`: saúde do servidor PHP
- `panel/`: painel em C# (ASP.NET Core / .NET 10): uptime, health, estatísticas por rota (tempo, CPU, memória,
  tempo esperando outros sistemas), tabela de sistemas externos e feed de acessos com o detalhe de cada chamada

## Rodar

```powershell
.\start.ps1
```

- Site: http://localhost:8080
- Serviços externos simulados: http://127.0.0.1:8070
- Painel: http://localhost:5050

## Monitorar o seu site

1. Copie `site/monitor/` para a raiz do seu site e, se quiser, crie `monitor/config.php`:
   ```php
   <?php return array(
       'token'    => 'troque-isto',
       'db'       => array('dsn' => 'mysql:host=127.0.0.1;dbname=site', 'user' => 'u', 'pass' => 'p'),
       'writable' => array(__DIR__ . '/../uploads'),
   );
   ```
2. Carregue o agente antes de todo script PHP (Apache):
   ```apache
   # .htaccess na raiz do site
   php_value auto_prepend_file "/caminho/do/site/monitor/agent.php"
   ```
   A pasta `monitor/data/` precisa ser gravável pelo PHP e já vem com `.htaccess` negando acesso público.
3. Adicione o alvo em `panel/appsettings.json` (`Monitor:Targets`) com `Url`, `HealthUrl`, `RequestsUrl` e `Token`.

Em dev, `php -S` usa `site/router.php`, que faz o papel do Apache (carrega o agente e responde 404).
O `start.ps1` desliga o profiler e o autostart do xdebug só nesses servidores (cada um somava ~200 ms por requisição).

## Medir chamadas a outros sistemas

O PHP não permite interceptar funções sem extensão, então a medição entra trocando uma linha onde a conexão é criada:

| O site usa | Troque por | O painel mostra |
|---|---|---|
| `new PDO(...)` | `new MonitorPDO(...)` | conexão, `query`, `exec` e `execute` de prepared statements, com o SQL |
| `new mysqli(...)` | `new MonitorMysqli(...)` | conexão e `query` |
| `curl_exec($ch)` | `monitor_curl_exec($ch)` | status, DNS, conexão, TLS, 1º byte, total e bytes |
| `new SoapClient(...)` | `new MonitorSoapClient(...)` | cada chamada SOAP (action) |
| cliente Redis/Memcached/SDK | `Monitor::wrap($cliente, 'redis', 'host:6379')` | cada método chamado |
| qualquer bloco | `Monitor::span('fila', 'rabbitmq', 'publicar', function () { ... })` | o bloco |

`file_get_contents()`/`fopen()` em URLs são medidos sem mudar código se `MONITOR_AUTO_HTTP` estiver ligado
(`define('MONITOR_AUTO_HTTP', true)` antes do agente, ou `SetEnv MONITOR_AUTO_HTTP 1` no Apache).
Com ele ligado, a variável `$http_response_header` deixa de ser preenchida no código do site — confira se o site a usa.

URLs aparecem sem query string e DSNs sem usuário/senha. O SQL aparece como escrito: prefira prepared statements
para que valores (CPF, e-mail...) não cheguem ao painel.

## CPU e memória

- **CPU** vem de `getrusage()`: tempo de processador gasto pelo processo durante a requisição (usuário + sistema).
  No Apache prefork/PHP-FPM é exato por requisição; no Apache do Windows (multi-thread) pode incluir outras threads.
  No Windows a precisão é de ~15,6 ms, então páginas rápidas aparecem como 0 ou 16 ms.
- **Memória** mostra o pico realmente usado pelo script (`memory_get_peak_usage(false)`) e o reservado pelo PHP (blocos de 2 MB).
- **Onde o tempo vai**: CPU, esperando outros sistemas e o resto (I/O local, rede com o navegador, fila do servidor).

## Simular falhas

`/demo/chaos.php?mode=ok|slow|error|down` (ou os botões no painel). Os serviços externos já falham sozinhos:
pagamento devolve 502 em ~15% das chamadas e o estoque às vezes demora 3 s.

## Limitações do protótipo

- Histórico, incidentes e estatísticas ficam em memória (somem ao reiniciar o painel).
- Sem autenticação no painel e sem envio de alertas (e-mail/Telegram/etc.).
