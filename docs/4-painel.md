# 4. Painel (IIS, um servidor)

O painel é uma aplicação ASP.NET Core (.NET 10) hospedada no IIS. Ele faz duas coisas:
- consulta o ClickHouse para mostrar rotas, chamadas filhas, sistemas externos, servidores e acessos;
- chama a URL e o `/_monitor/health.php` de cada site para medir uptime e health.

Há um painel só, para todos os sites. Antes: ClickHouse com o usuário `monitor_panel` (guia 1) e o token do agente (guia 2).

> **O painel não tem login próprio.** Restrinja o acesso no IIS (passo 6) ou pela rede.

## 1. Pré-requisitos no servidor

- **IIS** com os recursos `Web-Server`, `Web-Windows-Auth` e `Web-Url-Auth`:
  ```powershell
  Install-WindowsFeature Web-Server, Web-Windows-Auth, Web-Url-Auth -IncludeManagementTools
  ```
- **ASP.NET Core Hosting Bundle .NET 10**, do site oficial do .NET (dotnet.microsoft.com, "Hosting Bundle").
  Depois de instalar, rode `iisreset`.
- O certificado do ClickHouse precisa ser confiável neste servidor, como no guia 3, passo 4.

## 2. Gerar o pacote (máquina de build)

```powershell
dotnet publish panel -c Release -o .\publish\panel
Compress-Archive .\publish\panel\* .\publish\monitor-panel.zip -Force
```

O `appsettings.Local.json` não entra no pacote.

## 3. Copiar

```powershell
New-Item -ItemType Directory -Force D:\monitor\panel | Out-Null
Expand-Archive .\monitor-panel.zip D:\monitor\panel -Force
```

## 4. Configuração (`appsettings.Local.json`)

Crie `D:\monitor\panel\appsettings.Local.json` com um alvo por site:

```json
{
  "Monitor": {
    "ClickHouse": {
      "Url": "https://clickhouse.interno:8443",
      "Database": "monitor",
      "User": "monitor_panel",
      "Password": "SENHA_DO_PAINEL",
      "DefaultWindowMinutes": 60,
      "MaxWindowMinutes": 43200
    },
    "Targets": [
      {
        "Id": "loja",
        "Name": "Loja",
        "Url": "https://loja.exemplo.com.br/",
        "Site": "loja.exemplo.com.br",
        "HealthUrl": "https://loja.exemplo.com.br/_monitor/health.php",
        "Token": "TOKEN_DO_AGENTE",
        "TracesUrl": null,
        "TimeoutSeconds": 5,
        "SlowMs": 1000
      },
      {
        "Id": "portal",
        "Name": "Portal",
        "Url": "https://portal.exemplo.com.br/",
        "Site": "portal.exemplo.com.br",
        "HealthUrl": "https://portal.exemplo.com.br/_monitor/health.php",
        "Token": "TOKEN_DO_AGENTE"
      }
    ]
  }
}
```

| Campo | Observação |
|---|---|
| `Id` | identificador sem espaços, usado na URL da API |
| `Url` | página checada para uptime (a cada 10 s) |
| `Site` | nome da pasta em `D:\monitor\data\` (guia 2, "Conferir"). Sem este campo, o painel usa o host da `Url` |
| `HealthUrl` + `Token` | health do PHP. O token é o de `config.local.php`. Use o mesmo token em todos os servidores do site: o health passa pelo balanceador e pode cair em qualquer um deles |
| `ClickHouse:Url` | com este campo preenchido, os dados vêm do ClickHouse. `Nodes` e `TracesUrl` são ignorados |
| `DefaultWindowMinutes` / `MaxWindowMinutes` | período padrão e período máximo do seletor (43200 = 30 dias) |

> **Cuidado com o primeiro alvo.** O `appsettings.json` traz um alvo de protótipo (`localhost:8080`). Os alvos do
> `appsettings.Local.json` se mesclam **por posição** com ele. Por isso, preencha sempre no primeiro alvo todos os
> campos do exemplo (`Id`, `Name`, `Url`, `Site`, `HealthUrl`, `Token`, `TracesUrl: null`). Assim nada do
> protótipo sobra, como o token `dev-token`.

## 5. Site e pool no IIS

```powershell
Import-Module WebAdministration
New-WebAppPool MonitorPanel
Set-ItemProperty IIS:\AppPools\MonitorPanel -Name managedRuntimeVersion -Value ""          # No Managed Code
Set-ItemProperty IIS:\AppPools\MonitorPanel -Name startMode -Value AlwaysRunning
Set-ItemProperty IIS:\AppPools\MonitorPanel -Name processModel.idleTimeout -Value "00:00:00"
Set-ItemProperty IIS:\AppPools\MonitorPanel -Name recycling.periodicRestart.time -Value "00:00:00"

New-Website -Name MonitorPanel -PhysicalPath D:\monitor\panel -ApplicationPool MonitorPanel `
  -Port 443 -Ssl -HostHeader monitor.interno
Set-ItemProperty IIS:\Sites\MonitorPanel -Name applicationDefaults.preloadEnabled -Value $true

# certificado HTTPS (já instalado em LocalMachine\My)
$thumb = (Get-ChildItem Cert:\LocalMachine\My | Where-Object Subject -like "*monitor.interno*").Thumbprint
(Get-WebBinding -Name MonitorPanel -Protocol https).AddSslCertificate($thumb, "my")
```

O pool precisa ficar **sempre no ar**: a checagem de uptime roda em segundo plano dentro do painel. Com o
desligamento por ociosidade padrão do IIS (20 min), o histórico de uptime teria buracos. Por isso o script usa
`AlwaysRunning`, desliga o timeout de ociosidade e a reciclagem periódica, e liga o preload.

Permissões. A senha fica restrita ao pool:

```powershell
$pool = "IIS AppPool\MonitorPanel"
icacls D:\monitor\panel /grant "${pool}:(OI)(CI)RX"
icacls D:\monitor\panel\appsettings.Local.json /inheritance:r /grant:r "Administrators:F" "SYSTEM:F" "${pool}:R"
```

## 6. Restringir o acesso (Windows Authentication)

O acesso fica restrito a um grupo do AD, por exemplo `DOMINIO\Monitor-Leitores`:

```powershell
$appcmd = "$env:windir\system32\inetsrv\appcmd.exe"
& $appcmd set config MonitorPanel -section:system.webServer/security/authentication/anonymousAuthentication /enabled:false /commit:apphost
& $appcmd set config MonitorPanel -section:system.webServer/security/authentication/windowsAuthentication /enabled:true /commit:apphost
& $appcmd set config MonitorPanel -section:system.webServer/security/authorization /-"[accessType='Allow',users='*']" /commit:apphost
& $appcmd set config MonitorPanel -section:system.webServer/security/authorization /+"[accessType='Allow',roles='DOMINIO\Monitor-Leitores']" /commit:apphost
```

Alternativa: deixar o painel acessível só pela rede de administração (VPN ou firewall).

## 7. Conferir

1. Abra `https://monitor.interno/`. Os sites aparecem à esquerda, e o seletor **Período** aparece no topo, o que indica que a fonte é o ClickHouse.
2. Confira a fonte de dados pela API:
   ```powershell
   Invoke-RestMethod https://monitor.interno/api/info -UseDefaultCredentials   # source: clickhouse
   ```
3. Se o topo mostrar "erro: …", leia a mensagem:
   - a API responde 503 quando o ClickHouse está fora ou recusa a consulta;
   - confira a senha, o `HOST IP` do usuário `monitor_panel` e o certificado.
4. Se um site não mostrar rotas, confira se o `Site` é igual ao nome da pasta em `D:\monitor\data\` nos servidores.
   Também confira o período escolhido.

Erros na inicialização (por exemplo, JSON inválido no `appsettings.Local.json`) aparecem no Log de Eventos,
**Application**, origem "IIS AspNetCore Module V2".

## Atualizar

```powershell
Stop-WebAppPool MonitorPanel
Expand-Archive .\monitor-panel.zip D:\monitor\panel -Force
Start-WebAppPool MonitorPanel
```

O painel guarda em memória o histórico de uptime e os incidentes, e eles se perdem a cada reinício. Rotas e acessos
ficam no ClickHouse e não se perdem.
