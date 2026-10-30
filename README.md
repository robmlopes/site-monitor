# Monitor (protótipo)

- `site/`: site de exemplo em PHP 7.0.33 com várias rotas (produtos, busca, contato POST, API JSON,
  página lenta, erro 500, 404) e a página `/testes.php`, que gera tráfego automático
- `site/monitor/`: o que vai para o seu site
  - `agent.php`: registra cada requisição (rota, método, status, tempo, erro) em `monitor/data/requests.log`
  - `requests.php`: entrega esse log ao painel de forma incremental
  - `health.php`: saúde do servidor PHP
- `panel/`: painel em C# (ASP.NET Core / .NET 10): uptime, health, estatísticas por rota e feed de acessos ao vivo

## Rodar

```powershell
.\start.ps1
```

- Site: http://localhost:8080
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
O `start.ps1` desliga o profiler e o autostart do xdebug só nesse servidor (cada um somava ~200 ms por requisição).

## Simular falhas

`/demo/chaos.php?mode=ok|slow|error|down` (ou os botões no painel).

## Limitações do protótipo

- Histórico e incidentes ficam em memória (somem ao reiniciar).
- Sem autenticação no painel e sem envio de alertas (e-mail/Telegram/etc.).
