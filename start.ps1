# Sobe o protótipo inteiro localmente:
#   site PHP 7.0 em http://localhost:8080  (php -S)
#   painel C#    em http://localhost:5050  (dotnet run)
$root = $PSScriptRoot

$site = Start-Process php -ArgumentList '-d', 'xdebug.profiler_enable=0', '-d', 'xdebug.remote_autostart=0', '-S', 'localhost:8080', '-t', "$root\site", "$root\site\router.php" -PassThru -NoNewWindow
Write-Host "Site PHP no ar (pid $($site.Id)): http://localhost:8080"

try {
    Write-Host "Painel: http://localhost:5050"
    dotnet run --project "$root\panel" --launch-profile http
}
finally {
    Stop-Process -Id $site.Id -ErrorAction SilentlyContinue
}
