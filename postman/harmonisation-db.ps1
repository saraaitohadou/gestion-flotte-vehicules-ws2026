$ErrorActionPreference = "Stop"
Set-Location "C:\Users\nabi\Desktop\flotte"

$env:MYSQL_PWD = 'rootpassword'
$apiKey = (Select-String -Path .env -Pattern '^SERVICE_API_KEY=').Line.Split('=')[1]
$jwt = (docker compose exec -T entretien-app php artisan tinker --execute "echo App\Support\Jwt::encode(['sub'=>'admin-db-harm','name'=>'admin-db-harm','role'=>'ADMINISTRATEUR','exp'=>time()+3600], config('auth_jwt.secret'));").Trim()

$hVeh = @{ "X-API-Key" = $apiKey }
$hChauff = @{ "X-API-Key" = $apiKey }
$hEnt = @{ "Authorization" = "Bearer $jwt" }
$hCarb = @{}

$tmp = "C:\Users\nabi\AppData\Local\Temp\opencode\harmonisation-resp.json"
$bodyFile = "C:\Users\nabi\AppData\Local\Temp\opencode\harmonisation-body.json"
$log = @()
$vehiculeId = $null
$chauffeurId = $null
$pleinId = $null
$entretienId = $null
$entretienArchive = $false
$suffix = Get-Date -Format 'yyMMddHHmm'
$plaque = "TEST-HARM-$suffix"
$permis = "PERM-HARM-$suffix"

function Call-Http([string]$Method, [string]$Url, [hashtable]$Headers, [string]$Body) {
  $a = @("-s", "-o", $tmp, "-w", "%{http_code}", "-X", $Method)
  foreach ($k in $Headers.Keys) { $a += @("-H", "$k`: $($Headers[$k])") }
  if ($Body) {
    [IO.File]::WriteAllText($bodyFile, $Body, [Text.Encoding]::ASCII)
    $a += @("-H", "Content-Type: application/json", "-d", "@$bodyFile")
  }
  $a += $Url
  $code = (& curl.exe @a).Trim()
  $contenu = if (Test-Path $tmp) { (Get-Content $tmp -Raw -ErrorAction SilentlyContinue) } else { "" }
  return [pscustomobject]@{ Code = $code; Body = [string]$contenu }
}

function DbScalar([string]$Sql) {
  $sortie = docker compose exec -T -e MYSQL_PWD=rootpassword db-commune mysql -uroot -N -e $Sql 2>$null
  return [string]($sortie | Select-Object -Last 1)
}

# CIN : 2 lettres + 6 chiffres (format accepte par l'API). Tirage au sort d'un CIN
# encore inutilise pour que le script soit rejouable le meme jour (suppression logique).
do {
  $cin = "ZZ" + (Get-Random -Minimum 100000 -Maximum 999999)
  $cinExistant = DbScalar "SELECT COUNT(*) FROM gestion_flotte.chauffeurs WHERE cin='$cin'"
} while ($cinExistant -ne '0')

function Add-Step($etape, $methode, $url, $code, $extrait) {
  $script:log += [pscustomobject]@{ Etape = $etape; Methode = $methode; Url = $url; Http = $code; Extrait = $extrait }
}

function Assert-Http($etape, $reponse, [int[]]$attendus) {
  if ($attendus -notcontains [int]$reponse.Code) {
    throw "$etape : HTTP $($reponse.Code) au lieu de $($attendus -join '/'). Body: $($reponse.Body)"
  }
}

function Assert-Egal($etape, $attendu, $obtenu) {
  if ("$attendu" -ne "$obtenu") { throw "$etape : attendu '$attendu', obtenu '$obtenu'" }
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

$avant = [ordered]@{
  vehicules = DbScalar 'SELECT COUNT(*) FROM gestion_flotte.vehicules'
  chauffeurs = DbScalar 'SELECT COUNT(*) FROM gestion_flotte.chauffeurs'
  carburants = DbScalar 'SELECT COUNT(*) FROM gestion_flotte.carburants'
  entretiens = DbScalar 'SELECT COUNT(*) FROM gestion_flotte.entretiens'
  max_vehicule = DbScalar 'SELECT COALESCE(MAX(id),0) FROM gestion_flotte.vehicules'
  max_chauffeur = DbScalar 'SELECT COALESCE(MAX(id_chauffeur),0) FROM gestion_flotte.chauffeurs'
  max_carburant = DbScalar 'SELECT COALESCE(MAX(id),0) FROM gestion_flotte.carburants'
  max_entretien = DbScalar 'SELECT COALESCE(MAX(id),0) FROM gestion_flotte.entretiens'
  vehicule_2 = DbScalar 'SELECT CONCAT(statut,0x7C,kilometrage) FROM gestion_flotte.vehicules WHERE id=2'
}

try {
  # 1. Vehicule cree dans Vehicule -> visible depuis Chauffeur
  $body = '{"immatriculation":"' + $plaque + '","marque":"Peugeot","modele":"208","annee":2022,"kilometrage":1000,"typeCarburant":"Essence"}'
  $r = Call-Http POST "http://127.0.0.1:8001/api/vehicules" $hVeh $body
  Assert-Http "Creation vehicule" $r @(201)
  $vehiculeId = [int]($r.Body | ConvertFrom-Json).id
  Add-Step "1. Creation vehicule (source)" POST "http://127.0.0.1:8001/api/vehicules" $r.Code "id=$vehiculeId plaque=$plaque"

  $r = Call-Http GET "http://127.0.0.1:8002/api/vehicules-disponibles" $hChauff
  Assert-Http "Liste vehicules Chauffeur" $r @(200)
  $liste = @($r.Body | ConvertFrom-Json)
  $trouve = @($liste | Where-Object { $_.id -eq $vehiculeId }).Count
  Assert-Egal "Vehicule visible depuis Chauffeur" 1 $trouve
  Add-Step "1. Vehicule visible depuis Chauffeur (REST)" GET "http://127.0.0.1:8002/api/vehicules-disponibles" $r.Code "present=$trouve nb=$($liste.Count)"

  # 2. Chauffeur cree dans Chauffeur -> visible depuis Entretien et Carburant
  $body = '{"cin":"' + $cin + '","nom":"Harmonisation","prenom":"Test","telephone":"0612345678","numero_permis":"' + $permis + '","categorie_permis":"B","date_expiration_permis":"2030-01-01"}'
  $r = Call-Http POST "http://127.0.0.1:8002/api/chauffeurs" $hChauff $body
  Assert-Http "Creation chauffeur" $r @(201)
  $chauffeurId = [int]($r.Body | ConvertFrom-Json).id_chauffeur
  Add-Step "2. Creation chauffeur (source)" POST "http://127.0.0.1:8002/api/chauffeurs" $r.Code "id=$chauffeurId cin=$cin"

  $r = Call-Http GET "http://127.0.0.1:8004/api/chauffeurs" $hEnt
  Assert-Http "Liste chauffeurs Entretien" $r @(200)
  $listeC = @(($r.Body | ConvertFrom-Json).data)
  $trouveC = @($listeC | Where-Object { $_.id_chauffeur -eq $chauffeurId }).Count
  Assert-Egal "Chauffeur visible depuis Entretien" 1 $trouveC
  Add-Step "2. Chauffeur visible depuis Entretien (REST proxy)" GET "http://127.0.0.1:8004/api/chauffeurs" $r.Code "present=$trouveC nb=$($listeC.Count)"

  # 3. Plein Carburant : verifie vehicule + chauffeur par REST, puis ecrit dans la base commune
  $body = '{"vehicule_id":' + $vehiculeId + ',"chauffeur_id":' + $chauffeurId + ',"date_plein":"2026-11-24","quantite_litres":45.5,"prix_litre":11.5,"kilometrage":1200,"type_carburant":"essence","station":"Station Test","plein_complet":true}'
  $r = Call-Http POST "http://127.0.0.1:8003/carburants" $hCarb $body
  Assert-Http "Creation plein" $r @(201)
  $pleinId = [int]($r.Body | ConvertFrom-Json).id
  Add-Step "3. Plein cree apres verification REST vehicule+chauffeur" POST "http://127.0.0.1:8003/carburants" $r.Code "id=$pleinId vehicule=$vehiculeId chauffeur=$chauffeurId"

  $dbPlein = DbScalar "SELECT CONCAT(id,'|',vehicule_id,'|',chauffeur_id,'|',kilometrage) FROM gestion_flotte.carburants WHERE id=$pleinId"
  Assert-Egal "Plein present dans gestion_flotte" "$pleinId|$vehiculeId|$chauffeurId|1200" $dbPlein
  Add-Step "4. Plein lu dans la base commune" DB "gestion_flotte.carburants" "200" "$dbPlein"

  # 3bis. Le meme vehicule est accessible depuis Entretien via le proxy REST
  $r = Call-Http GET "http://127.0.0.1:8004/api/vehicules/$vehiculeId" $hEnt
  Assert-Http "Detail vehicule Entretien" $r @(200)
  $detail = ($r.Body | ConvertFrom-Json).data
  Assert-Egal "Vehicule accessible depuis Entretien" $vehiculeId $detail.id
  Add-Step "3. Vehicule accessible depuis Entretien (REST proxy)" GET "http://127.0.0.1:8004/api/vehicules/$vehiculeId" $r.Code "immatriculation=$($detail.immatriculation) statut=$($detail.statut)"

  # 5 + 6. Entretien ecrit dans la base commune et met a jour statut/km via REST
  $r = Call-Http GET "http://127.0.0.1:8001/api/vehicules/2" $hVeh
  $v2Avant = $r.Body | ConvertFrom-Json
  Add-Step "6. Vehicule #2 avant cycle" GET "http://127.0.0.1:8001/api/vehicules/2" $r.Code "statut=$($v2Avant.statut) km=$($v2Avant.kilometrage)"

  $body = '{"vehicule_id":2,"type":"PREVENTIF","categorie":"VIDANGE","description":"Test harmonisation DB","date_entretien":"2026-11-24","kilometrage":62500,"cout":600}'
  $r = Call-Http POST "http://127.0.0.1:8004/api/entretiens" $hEnt $body
  Assert-Http "Creation entretien" $r @(201)
  $entretienId = [int]($r.Body | ConvertFrom-Json).data.id
  Add-Step "5. Entretien cree" POST "http://127.0.0.1:8004/api/entretiens" $r.Code "id=$entretienId"

  $dbEnt = DbScalar "SELECT CONCAT(id,'|',statut,'|',kilometrage) FROM gestion_flotte.entretiens WHERE id=$entretienId"
  Add-Step "5. Entretien lu dans la base commune" DB "gestion_flotte.entretiens" "200" "$dbEnt"

  $r = Call-Http PATCH "http://127.0.0.1:8004/api/entretiens/$entretienId/demarrer" $hEnt
  Assert-Http "Demarrer entretien" $r @(200)
  $w = Wait-Vehicule "EN_MAINTENANCE" 90
  Add-Step "6. Vehicule EN_MAINTENANCE (poll REST)" GET "http://127.0.0.1:8001/api/vehicules/2" $w.Http "statut=$($w.Statut) km=$($w.Km) tentatives=$($w.Tentatives)"

  $r = Call-Http PATCH "http://127.0.0.1:8004/api/entretiens/$entretienId/terminer" $hEnt '{"kilometrage":62500,"cout":600}'
  Assert-Http "Terminer entretien" $r @(200)
  $w = Wait-Vehicule "DISPONIBLE" 90
  Add-Step "6. Vehicule DISPONIBLE, kilometrage non decreasing" GET "http://127.0.0.1:8001/api/vehicules/2" $w.Http "statut=$($w.Statut) km=$($w.Km) tentatives=$($w.Tentatives)"
  Assert-Egal "Kilometrage coherent" 62500 $w.Km

  $r = Call-Http DELETE "http://127.0.0.1:8004/api/entretiens/$entretienId" $hEnt
  Assert-Http "Archivage entretien test" $r @(200)
  $entretienArchive = $true
  Add-Step "5. Entretien test archive (ligne conservee)" DELETE "http://127.0.0.1:8004/api/entretiens/$entretienId" $r.Code "archive=$(($r.Body | ConvertFrom-Json).data.archive)"
}
finally {
  if ($pleinId) {
    $r = Call-Http DELETE "http://127.0.0.1:8003/carburants/$pleinId" $hCarb
    Add-Step "Nettoyage plein test" DELETE "http://127.0.0.1:8003/carburants/$pleinId" $r.Code "supprime"
  }
  if ($entretienId -and -not $entretienArchive) {
    # Un entretien PLANIFIE/EN_COURS ne peut pas etre archive : on le termine d'abord
    # proprement via l'API (annulation = liberation du vehicule), puis on archive.
    $statut = DbScalar "SELECT statut FROM gestion_flotte.entretiens WHERE id=$entretienId"
    if ($statut -in @('PLANIFIE', 'EN_COURS')) {
      $r = Call-Http PATCH "http://127.0.0.1:8004/api/entretiens/$entretienId/annuler" $hEnt
      Add-Step "Nettoyage entretien test - annulation" PATCH "http://127.0.0.1:8004/api/entretiens/$entretienId/annuler" $r.Code "statut=$statut"
    }
    $r = Call-Http DELETE "http://127.0.0.1:8004/api/entretiens/$entretienId" $hEnt
    Add-Step "Nettoyage entretien test - archivage logique" DELETE "http://127.0.0.1:8004/api/entretiens/$entretienId" $r.Code "archive=$(($r.Body | ConvertFrom-Json).data.archive)"
  }
  if ($chauffeurId) {
    $r = Call-Http DELETE "http://127.0.0.1:8002/api/chauffeurs/$chauffeurId" $hChauff
    Add-Step "Nettoyage chauffeur test (desactivation logique)" DELETE "http://127.0.0.1:8002/api/chauffeurs/$chauffeurId" $r.Code "statut=INACTIF"
  }
  if ($vehiculeId) {
    $r = Call-Http DELETE "http://127.0.0.1:8001/api/vehicules/$vehiculeId" $hVeh
    Add-Step "Nettoyage vehicule test" DELETE "http://127.0.0.1:8001/api/vehicules/$vehiculeId" $r.Code "supprime"
  }
}

$apres = [ordered]@{
  vehicules = DbScalar 'SELECT COUNT(*) FROM gestion_flotte.vehicules'
  chauffeurs = DbScalar 'SELECT COUNT(*) FROM gestion_flotte.chauffeurs'
  carburants = DbScalar 'SELECT COUNT(*) FROM gestion_flotte.carburants'
  entretiens = DbScalar 'SELECT COUNT(*) FROM gestion_flotte.entretiens'
  lignes_originales_vehicules = DbScalar "SELECT COUNT(*) FROM gestion_flotte.vehicules WHERE id <= $($avant.max_vehicule)"
  lignes_originales_chauffeurs = DbScalar "SELECT COUNT(*) FROM gestion_flotte.chauffeurs WHERE id_chauffeur <= $($avant.max_chauffeur)"
  lignes_originales_carburants = DbScalar "SELECT COUNT(*) FROM gestion_flotte.carburants WHERE id <= $($avant.max_carburant)"
  lignes_originales_entretiens = DbScalar "SELECT COUNT(*) FROM gestion_flotte.entretiens WHERE id <= $($avant.max_entretien)"
  vehicule_2 = DbScalar 'SELECT CONCAT(statut,0x7C,kilometrage) FROM gestion_flotte.vehicules WHERE id=2'
  chauffeur_test_statut = if ($chauffeurId) { DbScalar "SELECT statut FROM gestion_flotte.chauffeurs WHERE id_chauffeur=$chauffeurId" } else { "n/a" }
  entretien_test_archive = if ($entretienId) { DbScalar "SELECT IF(archive_at IS NOT NULL,'1','0') FROM gestion_flotte.entretiens WHERE id=$entretienId" } else { "n/a" }
}

Assert-Egal "Preservation lignes vehicules" $avant.vehicules $apres.lignes_originales_vehicules
Assert-Egal "Preservation lignes chauffeurs" $avant.chauffeurs $apres.lignes_originales_chauffeurs
Assert-Egal "Preservation lignes carburants" $avant.carburants $apres.lignes_originales_carburants
Assert-Egal "Preservation lignes entretiens" $avant.entretiens $apres.lignes_originales_entretiens
Assert-Egal "Vehicule #2 inchange" $avant.vehicule_2 $apres.vehicule_2
Assert-Egal "Chauffeur test inactif" "INACTIF" $apres.chauffeur_test_statut
Assert-Egal "Entretien test archive" "1" $apres.entretien_test_archive

$resultat = [ordered]@{
  date = (Get-Date).ToString('o')
  base = 'gestion_flotte'
  avant = $avant
  apres = $apres
  ids = [ordered]@{ vehicule = $vehiculeId; chauffeur = $chauffeurId; plein = $pleinId; entretien = $entretienId }
  etapes = $log
  resultat = 'OK - 6 scenarios verifies, donnees originales preservees'
}

$chemin = "C:\Users\nabi\Desktop\flotte\postman\evidence\harmonisation-db.json"
[IO.File]::WriteAllText($chemin, ($resultat | ConvertTo-Json -Depth 8), [Text.UTF8Encoding]::new($false))

$log | Format-Table -AutoSize | Out-String -Width 400
"EVIDENCE=$chemin"
"RESULTAT OK : 6 scenarios verifies. Lignes originales preservees."
