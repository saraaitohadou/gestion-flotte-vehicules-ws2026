// Faux service Véhicule (en mémoire) pour développer/tester le service Entretien seul.
const http = require('http');

const vehicules = {
  1: { id: 1, immatriculation: '12345-A-6', statut: 'DISPONIBLE',   kilometrage: 45000 },
  2: { id: 2, immatriculation: '67890-B-1', statut: 'DISPONIBLE',   kilometrage: 120000 },
  3: { id: 3, immatriculation: '11111-C-2', statut: 'HORS_SERVICE', kilometrage: 300000 },
};

const send = (res, code, body) => {
  res.writeHead(code, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify(body));
};

http.createServer((req, res) => {
  let raw = '';
  req.on('data', (c) => (raw += c));
  req.on('end', () => {
    const body = raw ? JSON.parse(raw) : {};
    const m = req.url.match(/^\/api\/vehicules\/(\d+)(\/(statut|kilometrage))?$/);
    console.log(req.method, req.url, raw);
    if (!m) return send(res, 404, { message: 'Not found' });
    const v = vehicules[m[1]];
    if (!v) return send(res, 404, { message: 'Véhicule introuvable' });

    if (req.method === 'GET' && !m[2]) return send(res, 200, v);
    if (req.method === 'PATCH' && m[3] === 'statut') { v.statut = body.statut; return send(res, 200, v); }
    if (req.method === 'PATCH' && m[3] === 'kilometrage') {
      if (body.kilometrage < v.kilometrage) return send(res, 422, { message: 'Kilométrage décroissant' });
      v.kilometrage = body.kilometrage; return send(res, 200, v);
    }
    send(res, 405, { message: 'Méthode non supportée' });
  });
}).listen(8001, () => console.log('Mock Véhicule sur :8001'));
