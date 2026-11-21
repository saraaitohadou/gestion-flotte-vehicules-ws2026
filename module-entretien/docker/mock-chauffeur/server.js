// Faux service Chauffeur (en memoire) : GET /api/chauffeurs/{id}/eligibilite
const http = require('http');

const chauffeurs = {
  1: { id_chauffeur: 1, statut: 'EN_MISSION', permis_valide: true,  selectionnable: true,  affectable: false, motifs: ['DEJA_EN_MISSION'] },
  2: { id_chauffeur: 2, statut: 'DISPONIBLE', permis_valide: true,  selectionnable: true,  affectable: true,  motifs: [] },
  3: { id_chauffeur: 3, statut: 'EN_CONGE',   permis_valide: true,  selectionnable: false, affectable: false, motifs: ['CHAUFFEUR_EN_CONGE'] },
  4: { id_chauffeur: 4, statut: 'INACTIF',    permis_valide: true,  selectionnable: false, affectable: false, motifs: ['CHAUFFEUR_INACTIF'] },
};

const send = (res, code, body) => {
  res.writeHead(code, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify(body));
};

http.createServer((req, res) => {
  console.log(req.method, req.url);
  const m = req.url.match(/^\/api\/chauffeurs\/(\d+)\/eligibilite$/);
  if (req.method !== 'GET' || !m) return send(res, 404, { detail: { erreur: 'ROUTE_INCONNUE' } });
  const c = chauffeurs[m[1]];
  if (!c) return send(res, 404, { detail: { erreur: 'CHAUFFEUR_INTROUVABLE', message: 'Aucun chauffeur avec cet id.' } });
  send(res, 200, c);
}).listen(8003, () => console.log('Mock Chauffeur sur :8003'));