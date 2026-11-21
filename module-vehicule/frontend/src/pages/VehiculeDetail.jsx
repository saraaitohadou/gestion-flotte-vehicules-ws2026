import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { vehicules as api } from '../api'
import { Alerte, BTN, Badge, STATUTS, SupprimerModal, inp, km } from '../ui'
import VehiculeForm from '../VehiculeForm'

export default function VehiculeDetail() {
  const { id } = useParams()
  const nav = useNavigate()
  const [v, setV] = useState(null)
  const [dispo, setDispo] = useState(null)
  const [msg, setMsg] = useState(null) // { ok, texte }
  const [statut, setStatut] = useState('')
  const [nouveauKm, setNouveauKm] = useState('')
  const [edition, setEdition] = useState(false)
  const [suppression, setSuppression] = useState(false)

  const charger = () =>
    Promise.all([api.obtenir(id), api.disponibilite(id)])
      .then(([a, b]) => { setV(a); setDispo(b.disponible) })
      .catch((e) => setMsg({ ok: false, texte: e.message }))
  useEffect(() => { charger() }, [id])

  const agir = async (fn, texte) => {
    try { await fn(); setMsg({ ok: true, texte }); setStatut(''); setNouveauKm(''); charger() }
    catch (e) { setMsg({ ok: false, texte: e.message }) }
  }

  const supprimer = async () => {
    try { await api.supprimer(id); nav('/vehicules') }
    catch (e) { setMsg({ ok: false, texte: e.message }); setSuppression(false) }
  }

  if (!v) return msg ? <Alerte>{msg.texte}</Alerte> : <p className="text-slate-500">Chargement…</p>

  const options = Object.keys(STATUTS).filter((s) => s !== v.statut)
  const carte = 'rounded-xl bg-white p-5 shadow-sm'

  return (
    <>
      <Link to="/vehicules" className="text-sm text-brand hover:underline">← Retour à la liste</Link>
      <div className="mb-5 mt-2 flex items-center justify-between">
        <h1 className="flex items-center gap-3 text-2xl font-bold">
          Fiche du véhicule {v.immatriculation} <Badge statut={v.statut} />
        </h1>
        <div className="flex gap-2">
          <button className={BTN.ghost} onClick={() => setEdition(true)}>Modifier</button>
          <button className="rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-50"
            onClick={() => setSuppression(true)}>Supprimer</button>
        </div>
      </div>

      {msg && <Alerte ok={msg.ok}>{msg.texte}</Alerte>}

      <div className={`${carte} mb-4 grid grid-cols-2 gap-4 md:grid-cols-5`}>
        {[['Marque', v.marque], ['Modèle', v.modele], ['Année', v.annee], ['Carburant', v.typeCarburant], ['Kilométrage', km(v.kilometrage)]].map(([l, val]) => (
          <div key={l}>
            <p className="text-xs font-semibold text-slate-500">{l}</p>
            <p className="font-bold">{val}</p>
          </div>
        ))}
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        <div className={carte}>
          <h2 className="mb-3 font-bold">Disponibilité</h2>
          <p className={`flex items-center gap-2 rounded-lg p-3 font-semibold ${dispo ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>
            <span className={`h-2.5 w-2.5 rounded-full ${dispo ? 'bg-green-600' : 'bg-red-600'}`} />
            {dispo ? 'Disponible' : 'Indisponible'}
          </p>
          <p className="mt-3 text-xs text-slate-500">
            {dispo ? 'Ce véhicule peut être affecté à un chauffeur.' : "Ce véhicule ne peut pas être affecté à un chauffeur pour l'instant."}
          </p>
        </div>

        <div className={carte}>
          <h2 className="mb-3 font-bold">Changer le statut</h2>
          <select className={inp()} value={statut} onChange={(e) => setStatut(e.target.value)}>
            <option value="">Choisir un statut</option>
            {options.map((s) => <option key={s}>{s}</option>)}
          </select>
          <p className="my-2 text-xs text-slate-500">{options.join(', ')}</p>
          <button className={BTN.primary} disabled={!statut}
            onClick={() => agir(() => api.changerStatut(id, statut), `Statut changé en ${statut}`)}>Appliquer</button>
        </div>

        <div className={carte}>
          <h2 className="mb-3 font-bold">Mettre à jour le kilométrage</h2>
          <div className="flex items-center gap-2">
            <input type="number" className={inp()} placeholder="Nouveau kilométrage"
              value={nouveauKm} onChange={(e) => setNouveauKm(e.target.value)} />
            <span className="mt-1 text-sm text-slate-500">km</span>
          </div>
          <p className="my-2 text-xs text-slate-500">La valeur doit être supérieure ou égale à {km(v.kilometrage)}</p>
          <button className={BTN.primary} disabled={nouveauKm === ''}
            onClick={() => agir(() => api.majKilometrage(id, +nouveauKm), 'Kilométrage mis à jour')}>Mettre à jour</button>
        </div>
      </div>

      {edition && <VehiculeForm vehicule={v} onClose={() => setEdition(false)}
        onSaved={() => { setEdition(false); setMsg({ ok: true, texte: 'Véhicule modifié' }); charger() }} />}
      {suppression && <SupprimerModal v={v} onCancel={() => setSuppression(false)} onConfirm={supprimer} />}
    </>
  )
}
