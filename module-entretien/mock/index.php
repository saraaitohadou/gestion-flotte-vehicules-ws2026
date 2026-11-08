<?php
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/api/vehicules/(\d+)$#', $path, $m) && (int)$m[1] >= 1 && (int)$m[1] <= 5) {
    echo json_encode(['id' => (int)$m[1], 'immatriculation' => 'A-'.$m[1].'-1234', 'marque' => 'Dacia', 'modele' => 'Logan']);
    return;
}
http_response_code(404);
echo json_encode(['message' => 'Not found']);
