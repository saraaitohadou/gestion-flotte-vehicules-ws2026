import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { vehicules as api } from '../api'
import { Alerte, BTN, Badge, SupprimerModal, inp, km } from '../ui'
import VehiculeForm from '../VehiculeForm'

export default function Vehicules() {
  const [liste, setListe] = useState([])
  const [q, setQ] = useState('')
  const [erreur, setErreur] = useState('')
  const [form, setForm] = useState(null) // null = fermé, {} = création, véhicule = modification
  const [aSupprimer, setASupprimer] = useState(null)

  const charger = () => api.lister().then(setListe).catch((e) => setErreur(e.message))
  useEffect(() => { charger() }, [])

  const filtres = liste.filter((v) =>
    `${v.immatriculation} ${v.marque}`.toLowerCase().includes(q.toLowerCase()))

  const supprimer = async () => {
    try { await api.supprimer(aSupprimer.id); charger() }
    catch (e) { setErreur(e.message) }
    setASupprimer(null)
  }

  return (
    <div className="rounded-xl bg-white p-6 shadow-sm">
      <div className="mb-5 flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold">Liste des véhicules</h1>
          <p className="text-sm text-slate-500">Gérez les véhicules du parc automobile</p>
        </div>
        <button className={BTN.primary} onClick={() => setForm({})}>+ Ajouter un véhicule</button>
      </div>

      {erreur && <Alerte>{erreur}</Alerte>}
      <input className={`${inp()} max-w-xs`} placeholder="🔍 Rechercher par immatriculation ou marque"
        value={q} onChange={(e) => setQ(e.target.value)} />

      <div className="mt-4 overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="text-xs uppercase text-slate-500">
            <tr>
              {['Immatriculation', 'Marque', 'Modèle', 'Année', 'Kilométrage', 'Carburant', 'Statut', 'Actions'].map((h) => (
                <th key={h} className="px-3 py-3 font-semibold">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {filtres.map((v) => (
              <tr key={v.id} className="border-t border-slate-100">
                <td className="px-3 py-3.5 font-bold">{v.immatriculation}</td>
                <td className="px-3">{v.marque}</td>
                <td className="px-3">{v.modele}</td>
                <td className="px-3">{v.annee}</td>
                <td className="px-3 whitespace-nowrap">{km(v.kilometrage)}</td>
                <td className="px-3">{v.typeCarburant}</td>
                <td className="px-3"><Badge statut={v.statut} /></td>
                <td className="space-x-3 whitespace-nowrap px-3 text-xs font-semibold">
                  <Link className="text-brand hover:underline" to={`/vehicules/${v.id}`}>Voir</Link>
                  <button className="text-brand hover:underline" onClick={() => setForm(v)}>Modifier</button>
                  <button className="text-red-600 hover:underline" onClick={() => setASupprimer(v)}>Supprimer</button>
                </td>
              </tr>
            ))}
            {filtres.length === 0 && (
              <tr><td colSpan="8" className="py-8 text-center text-slate-500">Aucun véhicule trouvé</td></tr>
            )}
          </tbody>
        </table>
      </div>
      <p className="mt-3 text-xs text-slate-500">{filtres.length} véhicule{filtres.length > 1 ? 's' : ''}</p>

      {form && (
        <VehiculeForm vehicule={form.id ? form : null} onClose={() => setForm(null)}
          onSaved={() => { setForm(null); charger() }} />
      )}
      {aSupprimer && <SupprimerModal v={aSupprimer} onCancel={() => setASupprimer(null)} onConfirm={supprimer} />}
    </div>
  )
}
