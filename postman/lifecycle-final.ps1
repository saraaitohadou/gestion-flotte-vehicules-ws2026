$ErrorActionPreference = "Stop"
Set-Location "C:\Users\nabi\Desktop\flotte"
$apiKey = (Select-String -Path .env -Pattern '^SERVICE_API_KEY=').Line.Split('=')[1]
# JWT ADMINISTRATEUR genere a l'execution (plus de token perime => reproducible)
$token = (docker compose exec -T entretien-app php artisan tinker --execute "echo App\Support\Jwt::encode(['sub'=>'admin-audit','name'=>'admin-audit','role'=>'ADMINISTRATEUR','exp'=>time()+3600], config('auth_jwt.secret'));").Trim()
$hEnt = @{ "Authorization" = "Bearer $token" }
$hVeh = @{ "X-API-Key" = $apiKey }
$tmp = "C:\Users\nabi\AppData\Local\Temp\opencode\lifecycle-resp.json"
$log = @()

function Call-Http([string]$Method, [string]$Url, [hashtable]$Headers, [string]$Body) {
  $a = @("-s", "-o", $tmp, "-w", "%{http_code}", "-X", $Method)
  foreach ($k in $Headers.Keys) { $a += @("-H", "$k`: $($Headers[$k])") }
  if ($Body) {
    # PowerShell retire les guillemets doubles des arguments natifs : on passe par un fichier sans BOM.
    $bodyFile = "C:\Users\nabi\AppData\Local\Temp\opencode\lifecycle-body.json"
    [IO.File]::WriteAllText($bodyFile, $Body, [Text.Encoding]::ASCII)
    $a += @("-H", "Content-Type: application/json", "-d", "@$bodyFile")
  }
  $a += $Url
  $code = (& curl.exe @a).Trim()
  return [pscustomobject]@{ Code = $code; Body = (Get-Content $tmp -Raw) }
}

function Wait-Vehicule([string]$Attendu, [int]$MaxSecondes = 90) {
  $sw = [Diagnostics.Stopwatch]::StartNew()
  $n = 0
  while ($sw.Elapsed.TotalSeconds -lt $MaxSecondes) {
    $n++
    $r = Call-Http GET "http://127.0.0.1:8001/api/vehicules/2" $hVeh
    $v = $r.Body | ConvertFrom-Json
    if ($v.statut -eq $Attendu) {
      return [pscustomobject]@{ Statut = $v.statut; Km = $v.kilometrage; Tentatives = $n; Secondes = [math]::Round($sw.Elapsed.TotalSeconds, 2); Http = $r.Code }
    }
    Start-Sleep -Milliseconds 500
  }
  throw "Timeout apres ${MaxSecondes}s : statut $Attendu non observe"
}

function Add-Step($etape, $methode, $url, $code, $extrait) {
  $script:log += [pscustomobject]@{ Etape = $etape; Methode = $methode; Url = $url; Http = $code; Extrait = $extrait }
}

$r = Call-Http GET "http://127.0.0.1:8001/api/vehicules/2" $hVeh
$v = $r.Body | ConvertFrom-Json
Add-Step "Vehicule AVANT" GET "http://127.0.0.1:8001/api/vehicules/2" $r.Code "statut=$($v.statut) km=$($v.kilometrage)"

$r = Call-Http GET "http://127.0.0.1:8004/api/vehicules" $hEnt
Add-Step "Liste vehicules (proxy)" GET "http://127.0.0.1:8004/api/vehicules" $r.Code "nb=$(($r.Body | ConvertFrom-Json).data.Count)"

$r = Call-Http GET "http://127.0.0.1:8004/api/vehicules/2" $hEnt
$v2 = $r.Body | ConvertFrom-Json
Add-Step "Detail vehicule (NOUVEAU proxy)" GET "http://127.0.0.1:8004/api/vehicules/2" $r.Code "statut=$($v2.data.statut) km=$($v2.data.kilometrage)"

$body = '{"vehicule_id":2,"type":"PREVENTIF","categorie":"VIDANGE","description":"Test HTTP cycle final - Clio","date_entretien":"2026-11-24","kilometrage":62500,"cout":600}'
$r = Call-Http POST "http://127.0.0.1:8004/api/entretiens" $hEnt $body
$id = ($r.Body | ConvertFrom-Json).data.id
Add-Step "Creation entretien" POST "http://127.0.0.1:8004/api/entretiens" $r.Code "id=$id statut=$(($r.Body | ConvertFrom-Json).data.statut)"

$r = Call-Http GET "http://127.0.0.1:8001/api/vehicules/2" $hVeh
$v = $r.Body | ConvertFrom-Json
Add-Step "Apres PLANIFIER (inchange)" GET "http://127.0.0.1:8001/api/vehicules/2" $r.Code "statut=$($v.statut) km=$($v.kilometrage)"

$r = Call-Http PATCH "http://127.0.0.1:8004/api/entretiens/$id/demarrer" $hEnt
Add-Step "Demarrer" PATCH "http://127.0.0.1:8004/api/entretiens/$id/demarrer" $r.Code "statut=$(($r.Body | ConvertFrom-Json).data.statut)"

$w = Wait-Vehicule "EN_MAINTENANCE" 90
Add-Step "Vehicule EN_MAINTENANCE (poll <=90s)" GET "http://127.0.0.1:8001/api/vehicules/2" $w.Http "statut=$($w.Statut) km=$($w.Km) tentatives=$($w.Tentatives) secondes=$($w.Secondes)"

$r = Call-Http PATCH "http://127.0.0.1:8004/api/entretiens/$id/terminer" $hEnt '{"kilometrage":62500,"cout":600}'
Add-Step "Terminer" PATCH "http://127.0.0.1:8004/api/entretiens/$id/terminer" $r.Code "statut=$(($r.Body | ConvertFrom-Json).data.statut)"

$w = Wait-Vehicule "DISPONIBLE" 90
Add-Step "Vehicule DISPONIBLE (poll <=90s)" GET "http://127.0.0.1:8001/api/vehicules/2" $w.Http "statut=$($w.Statut) km=$($w.Km) tentatives=$($w.Tentatives) secondes=$($w.Secondes)"

$r = Call-Http DELETE "http://127.0.0.1:8004/api/entretiens/$id" $hEnt
Add-Step "Archiver entretien test" DELETE "http://127.0.0.1:8004/api/entretiens/$id" $r.Code "archive=$(($r.Body | ConvertFrom-Json).data.archive)"

$r = Call-Http GET "http://127.0.0.1:8001/api/vehicules/2" $hVeh
$v = $r.Body | ConvertFrom-Json
Add-Step "Vehicule APRES" GET "http://127.0.0.1:8001/api/vehicules/2" $r.Code "statut=$($v.statut) km=$($v.kilometrage)"

$log | Format-Table -AutoSize | Out-String -Width 400
$log | ConvertTo-Json -Depth 4 | Set-Content "C:\Users\nabi\Desktop\flotte\postman\evidence\final-lifecycle.json"
"ENTRETIEN_ID=$id"
