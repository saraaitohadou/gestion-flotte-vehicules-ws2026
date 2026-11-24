/* Interface web du service Carburant.
 * Application d'une seule page (JavaScript pur, sans outil de build) servie par
 * le service lui-meme sur /ui : elle appelle l'API sur la meme adresse.
 * Pour viser un autre serveur: definir window.API_URL avant ce script.
 */
const API = window.API_URL || "";
const PAGE = 20; // pleins par page dans la liste
const MOIS = ["Janv.", "Févr.", "Mars", "Avr.", "Mai", "Juin", "Juil.", "Août", "Sept.", "Oct.", "Nov.", "Déc."];
const paramsToken = new URLSearchParams(window.location.search).get("token");
if (paramsToken) {
  localStorage.setItem("flotte.carburant.token", paramsToken);
  localStorage.setItem("flotte.token", paramsToken);
  const params = new URLSearchParams(window.location.search);
  params.delete("token");
  const query = params.toString();
  window.history.replaceState({}, document.title, window.location.pathname + (query ? "?" + query : "") + window.location.hash);
}
const TOKEN = localStorage.getItem("flotte.carburant.token") || localStorage.getItem("flotte.token");
if (!TOKEN) {
  window.location.assign(window.CENTRAL_AUTH_URL || "http://localhost:5174/connexion?logout=1");
}
let ROLE = "";
if (TOKEN) {
  try {
    const payload = JSON.parse(atob(TOKEN.split(".")[1].replace(/-/g, "+").replace(/_/g, "/")));
    ROLE = payload.role || "";
  } catch {}
}
const EST_CHAUFFEUR = ROLE === "CHAUFFEUR";
if (EST_CHAUFFEUR) {
  document.querySelectorAll('[data-route="nouveau"]').forEach((element) => { element.hidden = true; });
}

const $ = (sel, racine = document) => racine.querySelector(sel);
const vue = $("#vue");

// ---------- Utilitaires ----------

function esc(valeur) {
  return String(valeur ?? "").replace(/[&<>"']/g, (c) => (
    { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]
  ));
}

function nombre(n, decimales = 2) {
  if (n === null || n === undefined) return "—";
  return Number(n).toLocaleString("fr-MA", { minimumFractionDigits: decimales, maximumFractionDigits: decimales });
}

function dateFr(iso) {
  return iso ? iso.split("-").reverse().join("/") : "—";
}

function aujourdhui() {
  return new Date().toISOString().slice(0, 10);
}

function qs(params) {
  const p = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v !== "" && v !== null && v !== undefined) p.set(k, v);
  });
  const s = p.toString();
  return s ? "?" + s : "";
}

function messageErreur(statut, data) {
  const d = data && data.detail;
  if (Array.isArray(d)) {
    return d.map((e) => `${(e.loc || []).slice(1).join(".")} : ${e.msg}`).join(" ; ");
  }
  if (typeof d === "string") return d;
  return `Erreur ${statut}`;
}

async function api(chemin, options = {}) {
  let reponse;
  try {
    const headers = { "Content-Type": "application/json", ...(TOKEN ? { Authorization: `Bearer ${TOKEN}` } : {}) };
    reponse = await fetch(API + chemin, {
      ...options,
      headers: { ...headers, ...(options.headers || {}) },
    });
  } catch (e) {
    throw new Error("Impossible de joindre le service Carburant.");
  }
  if (reponse.status === 204) return null;
  const data = await reponse.json().catch(() => null);
  if (!reponse.ok) throw new Error(messageErreur(reponse.status, data));
  return data;
}

let referencesChargees = null;
async function chargerReferences() {
  if (referencesChargees) return referencesChargees;
  // Les listes viennent des services Véhicule et Chauffeur via les relais
  // same-origin du service Carburant (évite CORS et token dans le navigateur).
  const [vehicules, chauffeurs] = await Promise.all([
    api("/references/vehicules"),
    api("/references/chauffeurs"),
  ]);
  referencesChargees = { vehicules, chauffeurs };
  return referencesChargees;
}

function referencesEnOptions(liste) {
  return (liste || []).map((ref) => (
    ref && typeof ref === "object"
      ? { id: ref.id, label: ref.label ?? String(ref.id) }
      : { id: ref, label: String(ref) }
  ));
}

function optionsIds(ids, selection, tout = false) {
  const options = referencesEnOptions(ids);
  return (tout ? `<option value="">Tous</option>` : `<option value="">Sélectionner</option>`) +
    options.map((o) => `<option value="${esc(o.id)}" ${String(selection) === String(o.id) ? "selected" : ""}>${esc(o.label)}</option>`).join("");
}

function csvValeur(valeur) {
  let texte = String(valeur ?? "");
  if (/^[=+\-@]/.test(texte)) texte = "'" + texte;
  return `"${texte.replace(/"/g, '""')}"`;
}

function exporterCSV(nom, lignes) {
  const csv = "\uFEFF" + lignes.map((ligne) => ligne.map(csvValeur).join(";")).join("\r\n");
  const lien = document.createElement("a");
  lien.href = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
  lien.download = nom;
  document.body.appendChild(lien);
  lien.click();
  lien.remove();
  URL.revokeObjectURL(lien.href);
}

let garderMessage = false; // un message de succes pose avant un changement de page survit a ce changement

function afficher(texte, type = "err") {
  garderMessage = type !== "err";
  const zone = $("#messages");
  zone.innerHTML = `<div class="msg ${type === "err" ? "err" : "okk"}">
    <span>${esc(texte)}</span><button type="button" aria-label="Fermer le message">×</button></div>`;
  $("button", zone).onclick = () => (zone.innerHTML = "");
}

function effacerMessage() {
  $("#messages").innerHTML = "";
}

function etat(p) {
  return p.anomalie
    ? `<span class="tag bad" title="${esc(p.message_anomalie)}">Surconsommation</span>`
    : `<span class="tag ok">Normal</span>`;
}

function cellulesPlein(p) {
  return `<td>${esc(dateFr(p.date_plein))}</td><td class="num">${esc(p.vehicule_id)}</td>
    <td class="num">${esc(p.chauffeur_id ?? "—")}</td><td>${esc(p.type_carburant)}</td>
    <td class="num">${nombre(p.quantite_litres, 1)}</td><td class="num">${nombre(p.prix_litre)}</td>
    <td class="num">${nombre(p.montant_total)}</td><td class="num">${nombre(p.kilometrage, 0)}</td>
    <td class="num">${nombre(p.consommation_l_100km, 1)}</td><td>${etat(p)}</td>`;
}

const ENTETE_PLEINS = `<th>Date</th><th>Véhicule</th><th>Chauffeur</th><th>Carburant</th><th>Litres</th>
  <th>Prix/L</th><th>Montant (MAD)</th><th>Km</th><th>Conso</th><th>État</th>`;

let triPleins = { champ: "date_plein", ordre: "desc" };

function enteteTriPleins() {
  const colonnes = [
    ["date_plein", "Date"], ["vehicule_id", "Véhicule"], ["chauffeur_id", "Chauffeur"],
    ["type_carburant", "Carburant"], ["quantite_litres", "Litres"], ["prix_litre", "Prix/L"],
    ["montant_total", "Montant (MAD)"], ["kilometrage", "Km"], ["consommation_l_100km", "Conso"],
  ];
  return colonnes.map(([champ, libelle]) => {
    const actif = triPleins.champ === champ;
    const fleche = actif ? (triPleins.ordre === "asc" ? " ▲" : " ▼") : "";
    return `<th class="sortable" scope="col" aria-sort="${actif ? (triPleins.ordre === "asc" ? "ascending" : "descending") : "none"}"><button type="button" data-tri="${esc(champ)}">${esc(libelle)}${fleche}</button></th>`;
  }).join("") + "<th scope=\"col\">État</th>" + (EST_CHAUFFEUR ? "" : "<th scope=\"col\"><span class=\"muted\">Actions</span></th>");
}

function trierPleins(pleins) {
  return pleins.slice().sort((a, b) => {
    let av = a[triPleins.champ] ?? "";
    let bv = b[triPleins.champ] ?? "";
    if (typeof av === "string" && typeof bv === "string") {
      av = av.toLocaleLowerCase("fr"); bv = bv.toLocaleLowerCase("fr");
    }
    const compare = av < bv ? -1 : av > bv ? 1 : 0;
    return triPleins.ordre === "asc" ? compare : -compare;
  });
}

async function majBadge() {
  try {
    const alertes = await api("/carburants/anomalies");
    const badge = $("#badge");
    badge.textContent = alertes.length;
    badge.hidden = alertes.length === 0;
  } catch (e) {
    /* le badge est facultatif */
  }
}

// ---------- Tableau de bord ----------

async function vueDashboard() {
  const [pleins, alertes] = await Promise.all([
    api("/carburants?limit=500"),
    api("/carburants/anomalies"),
  ]);
  const maintenant = new Date();
  const cleMois = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
  const courant = cleMois(maintenant);

  const duMois = pleins.filter((p) => p.date_plein.startsWith(courant));
  const cout = duMois.reduce((s, p) => s + p.montant_total, 0);
  const litres = duMois.reduce((s, p) => s + p.quantite_litres, 0);
  const consos = duMois.map((p) => p.consommation_l_100km).filter((c) => c !== null);
  const consoMoy = consos.length ? consos.reduce((s, c) => s + c, 0) / consos.length : null;

  const mois = [];
  for (let i = 5; i >= 0; i--) {
    const d = new Date(maintenant.getFullYear(), maintenant.getMonth() - i, 1);
    const cle = cleMois(d);
    mois.push({
      cle,
      label: MOIS[d.getMonth()],
      total: pleins.filter((p) => p.date_plein.startsWith(cle)).reduce((s, p) => s + p.montant_total, 0),
    });
  }
  const max = Math.max(...mois.map((m) => m.total), 1);

  vue.innerHTML = `
    <div class="grid4">
      <div class="card kpi"><span class="lbl">Coût du mois</span><span class="kv">${nombre(cout)} MAD</span></div>
      <div class="card kpi"><span class="lbl">Litres du mois</span><span class="kv">${nombre(litres, 1)} L</span></div>
      <div class="card kpi"><span class="lbl">Consommation moyenne</span><span class="kv">${nombre(consoMoy, 1)} <small>L/100 km</small></span></div>
      <div class="card kpi bad"><span class="lbl">Alertes actives</span><span class="kv" style="color:var(--bad)">${alertes.length}</span></div>
    </div>
    <div class="row">
      <div class="card pad" style="flex:2;min-width:300px;display:flex;flex-direction:column;gap:12px">
        <h2>Coût mensuel du carburant (MAD)</h2>
        <div class="chart" role="img" aria-label="Coût mensuel des six derniers mois">
          ${mois.map((m) => `<div class="col"><span class="lbl num">${nombre(m.total, 0)}</span>
            <div class="bar ${m.cle === courant ? "cur" : ""}" style="height:${Math.round((m.total / max) * 120)}px"></div></div>`).join("")}
        </div>
        <div class="xl">${mois.map((m) => `<span>${m.label}</span>`).join("")}</div>
      </div>
      <div class="card pad" style="flex:1;min-width:260px;display:flex;flex-direction:column;gap:12px">
        <div class="head" style="padding:0"><h2>Dernières alertes</h2><a class="docs" href="#/alertes">Voir tout</a></div>
        ${alertes.length === 0 ? `<p class="muted">Aucune alerte.</p>` : alertes.slice(0, 3).map((p) => `
          <div style="padding:10px 12px;background:var(--bad-soft);border-radius:8px">
            <div style="font-size:13px;font-weight:600">Véhicule ${esc(p.vehicule_id)} · ${esc(dateFr(p.date_plein))}</div>
            <div style="font-size:12px;color:var(--bad)">${nombre(p.consommation_l_100km, 1)} L/100 km</div></div>`).join("")}
      </div>
    </div>
    <div class="card">
      <div class="head"><h2>Derniers pleins</h2>${EST_CHAUFFEUR ? "" : `<a class="btn small" href="#/nouveau">Nouveau plein</a>`}</div>
      ${pleins.length === 0 ? `<div class="empty">Aucun plein enregistré pour le moment.</div>` : `
      <div class="tablewrap"><table><thead><tr>${ENTETE_PLEINS}</tr></thead>
        <tbody>${pleins.slice(0, 5).map((p) => `<tr>${cellulesPlein(p)}</tr>`).join("")}</tbody></table></div>`}
    </div>
    ${pleins.length >= 500 ? `<p class="muted">Les calculs portent sur les 500 pleins les plus récents.</p>` : ""}`;
}

// ---------- Liste des pleins ----------

let filtres = { vehicule_id: "", chauffeur_id: "", type_carburant: "", date_debut: "", date_fin: "", page: 0 };

async function vueListe() {
  const params = { ...filtres, skip: filtres.page * PAGE, limit: PAGE };
  delete params.page;
  const [pleins, references] = await Promise.all([
    api("/carburants" + qs(params)),
    chargerReferences(),
  ]);
  const pleinsTriés = trierPleins(pleins);

  vue.innerHTML = `
    <form class="card filters" id="form-filtres">
      <div class="f"><label for="f-veh">Véhicule</label><select id="f-veh" name="vehicule_id">${optionsIds(references.vehicules, filtres.vehicule_id, true)}</select></div>
      ${EST_CHAUFFEUR ? "" : `<div class="f"><label for="f-cha">Chauffeur</label><select id="f-cha" name="chauffeur_id">${optionsIds(references.chauffeurs, filtres.chauffeur_id, true)}</select></div>`}
      <div class="f"><label for="f-typ">Carburant</label><select id="f-typ" name="type_carburant">
        ${["", "diesel", "essence", "gpl"].map((t) => `<option value="${t}" ${filtres.type_carburant === t ? "selected" : ""}>${t || "Tous"}</option>`).join("")}</select></div>
      <div class="f"><label for="f-du">Du</label><input id="f-du" name="date_debut" type="date" value="${esc(filtres.date_debut)}"></div>
      <div class="f"><label for="f-au">Au</label><input id="f-au" name="date_fin" type="date" value="${esc(filtres.date_fin)}"></div>
      <button class="btn" type="submit">Filtrer</button>
      <button class="btn sec" type="button" id="reset">Réinitialiser</button>
    </form>
    <div class="card">
      <div class="head"><h2>${pleins.length} plein${pleins.length > 1 ? "s" : ""} affiché${pleins.length > 1 ? "s" : ""}</h2>
        <div class="actions">${EST_CHAUFFEUR ? "" : `<button class="btn sec small" type="button" id="export-csv">Exporter CSV</button><a class="btn small" href="#/nouveau">Nouveau plein</a>`}</div></div>
      ${pleins.length === 0 ? `<div class="empty">Aucun plein ne correspond à ces critères.</div>` : `
      <div class="tablewrap"><table><thead><tr>${enteteTriPleins()}</tr></thead><tbody>
        ${pleinsTriés.map((p) => `<tr>${cellulesPlein(p)}${EST_CHAUFFEUR ? "" : `<td><div class="actions">
          <a class="btn sec small" href="#/modifier/${p.id}">Modifier</a>
          <button class="btn danger small" type="button" data-suppr="${p.id}">Supprimer</button></div></td>`}</tr>`).join("")}
      </tbody></table></div>`}
      <div class="pager"><span class="muted">Page ${filtres.page + 1}</span>
        <div class="actions">
          <button class="btn sec small" type="button" id="prec" ${filtres.page === 0 ? "disabled" : ""}>Précédent</button>
          <button class="btn sec small" type="button" id="suiv" ${pleins.length < PAGE ? "disabled" : ""}>Suivant</button>
        </div></div>
    </div>`;

  $("#form-filtres").onsubmit = (e) => {
    e.preventDefault();
    const d = new FormData(e.target);
    filtres = { ...Object.fromEntries(d.entries()), page: 0 };
    route();
  };
  $("#reset").onclick = () => {
    filtres = { vehicule_id: "", chauffeur_id: "", type_carburant: "", date_debut: "", date_fin: "", page: 0 };
    route();
  };
  vue.querySelectorAll("[data-tri]").forEach((bouton) => {
    bouton.onclick = () => {
      const champ = bouton.dataset.tri;
      triPleins = { champ, ordre: triPleins.champ === champ && triPleins.ordre === "asc" ? "desc" : "asc" };
      route();
    };
  });
  if (!EST_CHAUFFEUR) $("#export-csv").onclick = async () => {
    const bouton = $("#export-csv");
    bouton.disabled = true;
    try {
      const exportParams = { ...filtres, limit: 500 };
      delete exportParams.page;
      const toutes = trierPleins(await api("/carburants" + qs(exportParams)));
      const lignes = [
        ["Date", "Véhicule", "Chauffeur", "Carburant", "Litres", "Prix/L (MAD)", "Montant (MAD)", "Kilométrage", "Conso (L/100 km)", "État"],
        ...toutes.map((p) => [p.date_plein, p.vehicule_id, p.chauffeur_id ?? "", p.type_carburant,
          p.quantite_litres, p.prix_litre, p.montant_total, p.kilometrage,
          p.consommation_l_100km ?? "", p.anomalie ? "Surconsommation" : "Normal"]),
      ];
      exporterCSV("pleins-carburant.csv", lignes);
    } catch (e) {
      afficher(e.message);
    } finally {
      bouton.disabled = false;
    }
  };
  $("#prec").onclick = () => { filtres.page--; route(); };
  $("#suiv").onclick = () => { filtres.page++; route(); };
  vue.querySelectorAll("[data-suppr]").forEach((b) => {
    b.onclick = async () => {
      if (!confirm("Supprimer définitivement ce plein ?")) return;
      try {
        await api(`/carburants/${b.dataset.suppr}`, { method: "DELETE" });
        afficher("Plein supprimé.", "ok");
        majBadge();
        route();
      } catch (e) {
        afficher(e.message);
      }
    };
  });
}

// ---------- Formulaire (création / modification) ----------

async function vueFormulaire(id) {
  const edition = id !== undefined;
  const [p, references] = await Promise.all([
    edition ? api(`/carburants/${id}`) : Promise.resolve({}),
    chargerReferences(),
  ]);
  const val = (champ, defaut = "") => esc(p[champ] ?? defaut);
  const type = p.type_carburant || "diesel";

  vue.innerHTML = `
    <div class="row" style="align-items:flex-start">
    <form class="card pad" id="form-plein" style="flex:2;min-width:300px;display:flex;flex-direction:column;gap:18px" novalidate>
      <div class="formgrid">
        <div class="f"><label for="vehicule_id">Véhicule *</label>
          <select id="vehicule_id" name="vehicule_id" required>${optionsIds(references.vehicules, p.vehicule_id)}</select>
          <span class="hint">Sélectionnez un véhicule enregistré</span></div>
        <div class="f"><label for="chauffeur_id">Chauffeur</label>
          <select id="chauffeur_id" name="chauffeur_id">${optionsIds(references.chauffeurs, p.chauffeur_id, false)}</select>
          <span class="hint">Facultatif</span></div>
        <div class="f"><label for="date_plein">Date du plein *</label>
          <input id="date_plein" name="date_plein" type="date" required value="${val("date_plein", aujourdhui())}"></div>
        <div class="f"><label for="station">Station</label>
          <input id="station" name="station" type="text" maxlength="100" value="${val("station")}"></div>
      </div>
      <fieldset class="f"><legend>Type de carburant *</legend>
        <div class="seg">${["diesel", "essence", "gpl"].map((t) => `
          <label><input type="radio" name="type_carburant" value="${t}" ${type === t ? "checked" : ""}><span>${t[0].toUpperCase() + t.slice(1)}</span></label>`).join("")}
        </div></fieldset>
      <div class="formgrid">
        <div class="f"><label for="quantite_litres">Quantité (litres) *</label>
          <input id="quantite_litres" name="quantite_litres" type="number" min="0.01" step="0.01" required value="${val("quantite_litres")}">
          <span class="hint">Strictement supérieure à 0</span></div>
        <div class="f"><label for="prix_litre">Prix par litre (MAD) *</label>
          <input id="prix_litre" name="prix_litre" type="number" min="0.01" step="0.01" required value="${val("prix_litre")}">
          <span class="hint">Strictement supérieur à 0</span></div>
        <div class="f"><label for="kilometrage">Kilométrage (km) *</label>
          <input id="kilometrage" name="kilometrage" type="number" min="0" step="1" required value="${val("kilometrage")}">
          <span class="hint">Strictement croissant pour un même véhicule</span></div>
      </div>
      <div style="display:flex;align-items:center;gap:10px">
        <input id="plein_complet" name="plein_complet" type="checkbox" ${p.plein_complet === false ? "" : "checked"}>
        <label for="plein_complet" style="font-size:14px;font-weight:500">Plein complet</label>
        <span class="muted">Nécessaire pour calculer la consommation</span></div>
      <div class="actions" style="justify-content:flex-end">
        <a class="btn sec" href="#/pleins">Annuler</a>
        <button class="btn" type="submit">${edition ? "Enregistrer les modifications" : "Enregistrer le plein"}</button>
      </div>
    </form>
    <div class="card pad" style="flex:1;min-width:260px;display:flex;flex-direction:column;gap:12px">
      <h2>Calculé automatiquement</h2>
      <div class="calc"><span class="lbl">Montant total</span><span class="kv" id="montant">—</span></div>
      <span class="muted">Montant = litres × prix, arrondi à 2 décimales. La consommation et l’alerte éventuelle sont calculées par le service à l’enregistrement.</span>
    </div></div>`;

  const form = $("#form-plein");
  const calcul = () => {
    const q = parseFloat($("#quantite_litres").value);
    const pr = parseFloat($("#prix_litre").value);
    $("#montant").textContent = q > 0 && pr > 0 ? `${nombre(Math.round(q * pr * 100) / 100)} MAD` : "—";
  };
  $("#quantite_litres").oninput = calcul;
  $("#prix_litre").oninput = calcul;
  calcul();

  form.onsubmit = async (e) => {
    e.preventDefault();
    effacerMessage();
    if (!form.reportValidity()) return;
    const d = new FormData(form);
    const corps = {
      vehicule_id: parseInt(d.get("vehicule_id"), 10),
      chauffeur_id: d.get("chauffeur_id") ? parseInt(d.get("chauffeur_id"), 10) : null,
      date_plein: d.get("date_plein"),
      station: d.get("station") ? d.get("station") : null,
      type_carburant: d.get("type_carburant"),
      quantite_litres: parseFloat(d.get("quantite_litres")),
      prix_litre: parseFloat(d.get("prix_litre")),
      kilometrage: parseInt(d.get("kilometrage"), 10),
      plein_complet: form.plein_complet.checked,
    };
    const bouton = $("button[type=submit]", form);
    bouton.disabled = true;
    bouton.textContent = "Enregistrement…";
    try {
      const resultat = await api(edition ? `/carburants/${id}` : "/carburants", {
        method: edition ? "PATCH" : "POST",
        body: JSON.stringify(corps),
      });
      majBadge();
      location.hash = "#/pleins";
      afficher(
        `Plein ${edition ? "modifié" : "enregistré"} : ${nombre(resultat.montant_total)} MAD` +
          (resultat.anomalie ? " — attention, surconsommation détectée." : "."),
        "ok"
      );
    } catch (err) {
      afficher(err.message);
      bouton.disabled = false;
      bouton.textContent = edition ? "Enregistrer les modifications" : "Enregistrer le plein";
    }
  };
}

// ---------- Alertes ----------

let filtresAlertes = { vehicule_id: "", date_debut: "", date_fin: "" };

async function vueAlertes() {
  const [alertes, references] = await Promise.all([
    api("/carburants/anomalies" + qs(filtresAlertes)),
    chargerReferences(),
  ]);
  vue.innerHTML = `
    <div class="card pad"><span style="font-size:14px;color:#3F4F4B">Un plein est signalé lorsque sa consommation dépasse de plus de <b>30 %</b>
      la moyenne des consommations précédentes du véhicule (au moins 2 relevés).</span></div>
    <form class="card filters" id="form-alertes">
      <div class="f"><label for="a-veh">Véhicule</label><select id="a-veh" name="vehicule_id">${optionsIds(references.vehicules, filtresAlertes.vehicule_id, true)}</select></div>
      <div class="f"><label for="a-du">Du</label><input id="a-du" name="date_debut" type="date" value="${esc(filtresAlertes.date_debut)}"></div>
      <div class="f"><label for="a-au">Au</label><input id="a-au" name="date_fin" type="date" value="${esc(filtresAlertes.date_fin)}"></div>
      <button class="btn" type="submit">Filtrer</button>
    </form>
    ${alertes.length === 0 ? `<div class="card"><div class="empty">Aucune alerte de surconsommation.</div></div>` :
      alertes.map((p) => `
      <div class="card alert">
        <div class="big">${nombre(p.consommation_l_100km, 1)}</div>
        <div class="mid"><strong>Véhicule ${esc(p.vehicule_id)}</strong>
          <span class="muted">Plein du ${esc(dateFr(p.date_plein))} · ${nombre(p.quantite_litres, 1)} L · ${nombre(p.kilometrage, 0)} km${p.chauffeur_id ? " · chauffeur " + esc(p.chauffeur_id) : ""}</span>
          <span style="font-size:13px;color:var(--bad)">${esc(p.message_anomalie)}</span></div>
        ${EST_CHAUFFEUR ? "" : `<a class="btn sec small" href="#/modifier/${p.id}">Vérifier la saisie</a>`}
      </div>`).join("")}
    <p class="muted">Corriger une erreur de saisie retire automatiquement l’alerte.</p>`;
  $("#form-alertes").onsubmit = (e) => {
    e.preventDefault();
    filtresAlertes = Object.fromEntries(new FormData(e.target).entries());
    route();
  };
}

// ---------- Statistiques d'un vehicule ----------

let filtresStats = { vehicule_id: "", date_debut: "", date_fin: "" };

async function vueStats() {
  const references = await chargerReferences();
  let resultat = "";
  if (filtresStats.vehicule_id) {
    const { vehicule_id, ...periode } = filtresStats;
    const [s, pleins] = await Promise.all([
      api(`/carburants/stats/vehicules/${encodeURIComponent(vehicule_id)}` + qs(periode)),
      api("/carburants" + qs({ vehicule_id, ...periode, limit: 500 })),
    ]);
    const chrono = pleins.slice().reverse();
    const maxConso = Math.max(...chrono.map((p) => p.consommation_l_100km || 0), 1);
    resultat = `
      <div class="grid3">
        <div class="card kpi"><span class="lbl">Nombre de pleins</span><span class="kv">${s.nombre_pleins}</span></div>
        <div class="card kpi"><span class="lbl">Total de litres</span><span class="kv">${nombre(s.total_litres, 1)} L</span></div>
        <div class="card kpi"><span class="lbl">Coût total</span><span class="kv">${nombre(s.cout_total)} MAD</span></div>
        <div class="card kpi"><span class="lbl">Distance parcourue</span><span class="kv">${nombre(s.distance_km, 0)} km</span></div>
        <div class="card kpi good"><span class="lbl">Consommation moyenne</span><span class="kv" style="color:var(--accent-dark)">${nombre(s.consommation_l_100km)} <small>L/100 km</small></span></div>
      </div>
      ${chrono.length === 0 ? "" : `
      <div class="card pad" style="display:flex;flex-direction:column;gap:12px">
        <h2>Consommation par plein (L/100 km)</h2>
        <div class="chart" role="img" aria-label="Consommation de chaque plein">
          ${chrono.map((p) => `<div class="col"><span class="lbl num">${nombre(p.consommation_l_100km, 1)}</span>
            <div class="bar ${p.anomalie ? "" : "cur"}" style="${p.anomalie ? "background:var(--bad);" : ""}height:${Math.round(((p.consommation_l_100km || 0) / maxConso) * 120)}px"></div></div>`).join("")}
        </div>
        <div class="xl">${chrono.map((p) => `<span>${dateFr(p.date_plein).slice(0, 5)}<br>${nombre(p.kilometrage, 0)} km</span>`).join("")}</div>
        <span class="muted">Le premier plein de la période sert de point de départ : il n’entre pas dans le calcul de la consommation.</span>
      </div>`}`;
  } else {
    resultat = `<div class="card"><div class="empty">Sélectionnez un véhicule pour afficher ses statistiques.</div></div>`;
  }
  vue.innerHTML = `
    <form class="card filters" id="form-stats">
      <div class="f" style="flex:2"><label for="s-veh">Véhicule *</label><select id="s-veh" name="vehicule_id" required>${optionsIds(references.vehicules, filtresStats.vehicule_id)}</select></div>
      <div class="f"><label for="s-du">Du</label><input id="s-du" name="date_debut" type="date" value="${esc(filtresStats.date_debut)}"></div>
      <div class="f"><label for="s-au">Au</label><input id="s-au" name="date_fin" type="date" value="${esc(filtresStats.date_fin)}"></div>
      <button class="btn" type="submit">Calculer</button>
    </form>${resultat}`;
  $("#form-stats").onsubmit = (e) => {
    e.preventDefault();
    filtresStats = Object.fromEntries(new FormData(e.target).entries());
    route();
  };
}

// ---------- Routage ----------

const TITRES = {
  "": "Tableau de bord",
  pleins: "Liste des pleins",
  nouveau: "Enregistrer un plein",
  modifier: "Modifier un plein",
  alertes: "Alertes de surconsommation",
  stats: "Statistiques d’un véhicule",
};

async function route() {
  const [section, param] = (location.hash.replace(/^#\/?/, "") || "").split("/");
  if (EST_CHAUFFEUR && (section === "nouveau" || section === "modifier")) {
    location.hash = "#/pleins";
    return;
  }
  const cle = section in TITRES ? section : "";
  document.querySelectorAll(".nav").forEach((a) => {
    const actif = a.dataset.route === (cle === "modifier" ? "pleins" : cle);
    a.classList.toggle("on", actif);
    if (actif) a.setAttribute("aria-current", "page"); else a.removeAttribute("aria-current");
  });
  $("#titre").textContent = TITRES[cle];
  document.title = `${TITRES[cle]} – Carburant`;
  if (!garderMessage) effacerMessage();
  garderMessage = false;
  vue.setAttribute("aria-busy", "true");
  vue.innerHTML = `<div class="loading" role="status"><span class="spinner" aria-hidden="true"></span><span>Chargement…</span></div>`;
  try {
    if (cle === "") await vueDashboard();
    else if (cle === "pleins") await vueListe();
    else if (cle === "nouveau") await vueFormulaire();
    else if (cle === "modifier") await vueFormulaire(param);
    else if (cle === "alertes") await vueAlertes();
    else if (cle === "stats") await vueStats();
  } catch (e) {
    vue.innerHTML = "";
    afficher(e.message);
  }
  vue.setAttribute("aria-busy", "false");
}

window.addEventListener("hashchange", () => route());
route();
majBadge();
