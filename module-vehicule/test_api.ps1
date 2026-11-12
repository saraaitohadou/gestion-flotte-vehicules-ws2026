$base = "http://127.0.0.1:8090"

function Tester($titre, $methode, $url, $attendu, $corps = $null) {
    $p = @{ Uri = "$base$url"; Method = $methode; UseBasicParsing = $true }
    if ($corps) { $p.Body = $corps; $p.ContentType = "application/json" }
    $contenu = $null
    try {
        $r = Invoke-WebRequest @p
        $code = [int]$r.StatusCode
        $contenu = $r.Content
    } catch {
        $code = [int]$_.Exception.Response.StatusCode
    }
    $etat = if ($code -eq $attendu) { "OK    " } else { "ECHEC " }
    Write-Host "$etat $titre -> $code (attendu $attendu)"
    return $contenu
}

$immat = "TEST-" + (Get-Random -Maximum 9999)
$json = '{"immatriculation":"' + $immat + '","marque":"Peugeot","modele":"208","annee":2022,"kilometrage":1000,"typeCarburant":"Essence"}'

$c = Tester "Creer un vehicule" POST "/vehicules" 201 $json
$id = ($c | ConvertFrom-Json).id

Tester "Immatriculation deja utilisee" POST "/vehicules" 409 $json | Out-Null
Tester "Creation sans marque" POST "/vehicules" 422 '{"immatriculation":"AAA-1-B","modele":"Clio","annee":2020,"typeCarburant":"Diesel"}' | Out-Null
Tester "Lister les vehicules" GET "/vehicules" 200 | Out-Null
Tester "Consulter le vehicule cree" GET "/vehicules/$id" 200 | Out-Null
Tester "Consulter un id inexistant" GET "/vehicules/99999" 404 | Out-Null
Tester "Verifier la disponibilite" GET "/vehicules/$id/disponibilite" 200 | Out-Null
Tester "Statut EN_MISSION" PATCH "/vehicules/$id/statut" 200 '{"statut":"EN_MISSION"}' | Out-Null
Tester "EN_MISSION vers EN_MAINTENANCE" PATCH "/vehicules/$id/statut" 409 '{"statut":"EN_MAINTENANCE"}' | Out-Null
Tester "Statut inconnu" PATCH "/vehicules/$id/statut" 422 '{"statut":"VOLE"}' | Out-Null
Tester "Supprimer un vehicule en mission" DELETE "/vehicules/$id" 409 | Out-Null
Tester "Kilometrage inferieur a l'actuel" PATCH "/vehicules/$id/kilometrage" 422 '{"kilometrage":100}' | Out-Null
Tester "Kilometrage valide" PATCH "/vehicules/$id/kilometrage" 200 '{"kilometrage":1800}' | Out-Null
Tester "Retour a DISPONIBLE" PATCH "/vehicules/$id/statut" 200 '{"statut":"DISPONIBLE"}' | Out-Null
Tester "Supprimer le vehicule de test" DELETE "/vehicules/$id" 204 | Out-Null
Tester "Vehicule supprime : consultation" GET "/vehicules/$id" 404 | Out-Null