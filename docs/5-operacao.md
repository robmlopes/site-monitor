# 5. Operação

## Conferência ponta a ponta (depois de instalar um servidor)

1. **Agente:** abra uma página do site. Deve surgir uma linha nova em `D:\monitor\data\<site>\traces-<hora atual>.jsonl`.
2. **Coletor:** em poucos segundos, o `Offset` daquele arquivo em `D:\monitor\data\collector-state.json` chega ao
   tamanho do arquivo.
3. **ClickHouse:** a requisição aparece na tabela:
   ```sql
   SELECT Timestamp, Server, Site, Method, Route, Status, DurationMs
   FROM monitor.requests ORDER BY Timestamp DESC LIMIT 5;
   ```
4. **Painel:** no site, com o período "15 min", a rota aparece em Rotas e o servidor em Servidores.
5. **Exclusão:** cerca de 70 min depois da virada da hora, o arquivo da hora anterior some. O Log de Eventos registra
   `... apagado: N requisição(ões) conferidas no ClickHouse`.

## O que acompanhar

| O quê | Como | Normal |
|---|---|---|
| Serviço do coletor | `Get-Service MonitorCollector` em cada servidor | `Running` |
| Arquivos parados | `Get-ChildItem D:\monitor\data\*\traces-*.jsonl \| Where-Object LastWriteTime -lt (Get-Date).AddHours(-3)` | vazio |
| Lotes recusados | `Get-ChildItem D:\monitor\data\rejected` | vazio |
| Servidores mandando dados | SQL abaixo | todos os servidores com dados recentes |
| Disco do ClickHouse | `df -h /var/lib/clickhouse` e a consulta de espaço do guia 1 | estável depois dos primeiros 30 dias (TTL) |

Servidores que pararam de enviar (última requisição há mais de 15 min):

```sql
SELECT Server, max(Timestamp) AS ultima, count() AS req_24h
FROM monitor.requests WHERE Timestamp > now() - INTERVAL 1 DAY
GROUP BY Server HAVING ultima < now() - INTERVAL 15 MINUTE;
```

## Problemas comuns

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| Nenhum arquivo em `data\<site>\` | `.user.ini` não aplicado, ou pool sem permissão em `data\` | guia 2, "Conferir"; procure linhas `[monitor]` no log de erros do PHP |
| Linhas `[monitor] ...` no log do PHP | falha do agente. O site não é afetado, só a medição daquela requisição | a mensagem diz o arquivo e o motivo (guia 2, "Se o agente falhar") |
| Aviso `arquivo pulado, nova tentativa em 1 min` | aquele arquivo está sem permissão ou o disco está cheio. Os demais seguem | conferir as permissões de `data\` (guia 3, passo 6) |
| Arquivos acumulam e `Offset` não anda | coletor parado ou sem acesso ao ClickHouse | Log de Eventos (`Monitor.Collector`); `Invoke-RestMethod https://clickhouse.interno:8443/ping` |
| `Falha ao enviar (... Authentication failed ...)` | senha errada, ou IP fora do `HOST IP` do usuário | conferir `appsettings.Local.json` e `SHOW CREATE USER monitor_collector` |
| `Falha ao enviar (... Not enough privileges ... routes_1m_mv)` | falta `SELECT ON monitor.requests` para o coletor | `GRANT` do guia 1, passo 6 |
| `The remote certificate is invalid` | certificado do ClickHouse não confiável no Windows | guia 3, passo 4 |
| Arquivo enviado mas não apagado, com aviso `ClickHouse tem X de Y` | dados ausentes no ClickHouse (TTL curto, tabela truncada, outro banco) | o arquivo fica e o coletor confere de novo a cada 10 min. Investigue antes de apagar à mão |
| Aviso `sem contagem do que foi enviado` | arquivo que já existia com `StartAtEnd`, ou estado antigo | normal: é apagado depois de `UnverifiedRetentionHours` (48 h) |
| Arquivos em `data\rejected\` | o ClickHouse recusou o lote (erro 4xx, por exemplo schema diferente) | abra o arquivo e o Log de Eventos. Depois de corrigir, os lotes podem ser reinseridos à mão |
| Painel mostra "erro: …" | ClickHouse fora, senha ou permissão do `monitor_panel` | guia 4, "Conferir" |
| Painel sem rotas para um site | `Site` diferente do nome da pasta, ou período curto | guia 4, "Conferir" |

## Reenviar um servidor (estado perdido ou corrompido)

Apagar `D:\monitor\data\collector-state.json` faz o coletor reenviar todos os arquivos que ainda estão em `data\`.
Isso é seguro: cada trecho reenviado leva o mesmo `insert_deduplication_token`, e o ClickHouse descarta o que já tem.
O ClickHouse lembra dos últimos 10 mil lotes por tabela.

```powershell
sc.exe stop MonitorCollector
Remove-Item D:\monitor\data\collector-state.json
sc.exe start MonitorCollector
```

## ClickHouse fora do ar

Nada a fazer nos servidores:
- o coletor tenta de novo com espera crescente, até 5 minutos entre tentativas;
- os arquivos se acumulam em `D:\monitor\data`, cerca de 2 KB por requisição com detalhe e menos sem detalhe;
- quando o ClickHouse volta, o atraso é enviado e os arquivos são apagados normalmente.

Para uma parada longa, acompanhe o espaço em disco do `D:` dos servidores web.

## Atualizações

- **Coletor:** guia 3, "Atualizar". O estado é mantido.
- **Painel:** guia 4, "Atualizar".
- **Agente:** substitua os arquivos de `D:\monitor\agent`, mantendo o `config.local.php`. O PHP passa a usar a versão nova
  na próxima requisição. Para regenerar os shims, apague `D:\monitor\data\<site>\generated\`.
- **Schema:** mudanças futuras no `schema.sql` são aplicadas na VM com o `default`, como no guia 1, passo 6.
  Os coletores usam `CreateSchema: false` e não criam nada.
