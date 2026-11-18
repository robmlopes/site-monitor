# 2. Agente PHP (cada servidor web e cada site)

O agente mede cada requisição e grava uma linha por requisição em `D:\monitor\data\<site>\traces-AAAAMMDD-HH.jsonl`.
Ele roda dentro do PHP do IIS e não precisa de serviço nem de extensão.
Requisito: PHP 7.0 ou mais novo. Hoje a integração automática cobre sites Zend Framework 2.

Todos os comandos são em **PowerShell como administrador**.

## Parte A: uma vez por servidor

### A1. Copiar o agente

Copie a pasta `agent\` do repositório para `D:\monitor\agent` e crie a pasta de dados:

```powershell
New-Item -ItemType Directory -Force D:\monitor\agent, D:\monitor\data | Out-Null
# copie o conteúdo de agent\ do repositório para D:\monitor\agent
```

O loader dos sites procura o agente, nesta ordem:
1. na variável de ambiente `MONITOR_HOME`;
2. na diretiva `monitor.home` do `php.ini`;
3. em `D:\monitor\agent`;
4. em `C:\monitor\agent`.

Se você usar a pasta padrão, não precisa configurar nada.

### A2. Permissões

O IIS só lê o agente. O agente grava em `data\` com a identidade do pool de cada site, e todos os pools fazem parte do grupo
`IIS_IUSRS`:

```powershell
icacls D:\monitor\agent /grant "IIS_IUSRS:(OI)(CI)RX"
icacls D:\monitor\data  /grant "IIS_IUSRS:(OI)(CI)M"
```

### A3. Configuração do servidor (`config.local.php`)

Crie `D:\monitor\agent\config.local.php` só com o que muda neste servidor. Esse arquivo não vai para o git. O resto
vem do `config.php`.

```php
<?php return array(
    'token'       => 'TOKEN_LONGO_ALEATORIO',  // o mesmo token vai na configuração do painel (guia 4)
    'server_name' => 'WEB-01',                 // opcional; o padrão é o COMPUTERNAME
    'sample_rate' => 0.05,                     // fração das requisições normais com detalhe das chamadas filhas
    'slow_ms'     => 1000,                     // acima disto a requisição sempre grava o detalhe
);
```

Para gerar um token:

```powershell
-join ((1..40) | ForEach-Object { '{0:x}' -f (Get-Random -Maximum 16) })
```

| Chave | Padrão | Observação |
|---|---|---|
| `token` | `dev-token` | **troque sempre.** Protege `/_monitor/health.php` e `/_monitor/traces.php` |
| `server_name` | `MONITOR_SERVER_NAME` ou `COMPUTERNAME` | aparece como "servidor" no painel |
| `data_dir` | `D:\monitor\data` (a pasta `data` ao lado do agente) | tem que ser a mesma pasta que o coletor lê |
| `sample_rate` | `1.0` | em produção, `0.01` a `0.1`. Requisições lentas ou com erro sempre gravam o detalhe |
| `slow_ms` | `1000` | |
| `sites` | `[]` | mapeia host para nome do site, ex.: `array('www.loja.com.br' => 'loja.com.br')`. Sem isso, o nome é o próprio host |
| `retention_hours` | `48` | não vale com o coletor: quem apaga os arquivos é o coletor, depois de conferir no ClickHouse |

Restrinja a leitura, porque o arquivo tem o token:

```powershell
icacls D:\monitor\agent\config.local.php /inheritance:r /grant:r "Administrators:F" "SYSTEM:F" "IIS_IUSRS:R"
```

## Parte B: em cada site ZF2

São três arquivos, **iguais para todos os sites**. Eles podem ir para o git do site, porque sem o agente instalado
não fazem nada.

| Arquivo no site | Conteúdo |
|---|---|
| `config\monitor-loader.php` | cópia de `agent\loader.php` |
| `public\.user.ini` | `auto_prepend_file = "D:\Sites\<site>\config\monitor-loader.php"` (caminho real do site) |
| `config\autoload\monitor.local.php` | cópia de `agent\zf2\site\monitor.local.php` |

- **Nunca** aponte o `.user.ini` direto para `D:\monitor\agent\prepend.php`. Se esse arquivo sumir, o PHP dá erro fatal
  em todas as páginas. O `monitor-loader.php` só carrega o agente se ele existir.
- O `monitor-loader.php` também define versões neutras de `MonitorPDO`, `monitor_curl_exec`, `Monitor::span` e
  `Monitor::wrap` quando o agente não carrega. Sites que trocaram uma linha para usar esses substitutos continuam
  funcionando sem o agente. Use sempre a versão atual do `agent\loader.php`: sites com uma cópia antiga devem recebê-la de novo.
- Se o site já tem um `.user.ini`, acrescente a linha nele.
- O PHP relê o `.user.ini` a cada 5 minutos (`user_ini.cache_ttl`). Para aplicar na hora, recicle o pool do site.

## Conferir

1. Abra uma página do site e confira que apareceu um arquivo novo:
   ```powershell
   Get-ChildItem D:\monitor\data\*\traces-*.jsonl | Sort-Object LastWriteTime | Select-Object -Last 3
   ```
   O nome da pasta é o nome do site, e é ele que o painel usa (`Site`). Anote esse nome.
2. Teste o health com o token:
   ```powershell
   Invoke-RestMethod https://loja.exemplo.com.br/_monitor/health.php -Headers @{ 'X-Monitor-Token' = 'TOKEN' }
   ```
   Sem o token, a resposta é 401.

Se nenhum arquivo aparecer:
- confira o caminho no `.user.ini` e se o pool já foi reciclado;
- confira a permissão de gravação em `D:\monitor\data` (A2);
- procure erros no log do PHP.

## Se o agente falhar

O agente nunca derruba o site. Se algo der errado com ele, a medição daquela requisição é perdida, e só isso.
Isso vale para:
- arquivo do agente ausente ou com erro;
- `config.local.php` inválido;
- pasta `data` sem permissão;
- versão do Doctrine ou do Redis diferente da esperada;
- erro dentro da própria medição.

O motivo vai para o log de erros do PHP, uma linha por requisição, começando com `[monitor]`:

```
[monitor] agente desligado nesta requisição: ParseError: ... em D:\monitor\agent\config.local.php:3
[monitor] ErrorException: mkdir(): Permission denied em D:\monitor\agent\src\agent.php:...
```

Testado no PHP 7.0.33 com um error handler que transforma qualquer aviso em exceção, como muitos sites ZF2 fazem.
O site respondeu normalmente nestes casos:
- `config.local.php` com erro de sintaxe ou sem array;
- `data_dir` inexistente;
- `agent.php` ausente;
- erro de sintaxe em `Monitor.php`, `instrument.php` ou `prepend.php`;
- agente removido.

## Remover de um site ou servidor

- **De um site:** tire a linha `auto_prepend_file` do `.user.ini` e recicle o pool.
- **De um servidor:** apague ou renomeie `D:\monitor\agent`. Os sites continuam funcionando, porque o loader não
  encontra o agente e não faz nada.
