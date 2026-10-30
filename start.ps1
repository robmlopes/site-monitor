# Sobe o protótipo inteiro localmente:
#   site PHP 7.0 em http://localhost:8080  (php -S)
#   serviços externos simulados em http://127.0.0.1:8070
#   painel C#    em http://localhost:5050  (dotnet run)
$root = $PSScriptRoot
$php = '-d', 'xdebug.profiler_enable=0', '-d', 'xdebug.remote_autostart=0'

$site = Start-Process php -ArgumentList ($php + '-S', 'localhost:8080', '-t', "$root\site", "$root\site\router.php") -PassThru -NoNewWindow
Write-Host "Site PHP no ar (pid $($site.Id)): http://localhost:8080"
$svc = Start-Process php -ArgumentList ($php + '-S', '127.0.0.1:8070', '-t', "$root\servicos", "$root\servicos\router.php") -PassThru -NoNewWindow
Write-Host "Serviços externos simulados (pid $($svc.Id)): http://127.0.0.1:8070"

try {
    Write-Host "Painel: http://localhost:5050"
    dotnet run --project "$root\panel" --launch-profile http
}
finally {
    Stop-Process -Id $site.Id, $svc.Id -ErrorAction SilentlyContinue
}
