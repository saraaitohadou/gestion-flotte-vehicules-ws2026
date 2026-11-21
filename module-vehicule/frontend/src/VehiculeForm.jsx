import { vehicules as api } from './api'
import { useEffect, useState } from 'react'
import { Alerte, BTN, Badge, CARBURANTS, Field, Modal, inp, km } from './ui'

const ANNEE_MAX = new Date().getFullYear()

// vehicule = null  -> création ; vehicule = {...} -> modification
export default function VehiculeForm({ vehicule, onClose, onSaved }) {
  const edition = Boolean(vehicule?.id)
  const [f, setF] = useState({
    immatriculation: vehicule?.immatriculation ?? '',
    marque: vehicule?.marque ?? '',
    modele: vehicule?.modele ?? '',
    annee: vehicule?.annee ?? '',
    kilometrage: vehicule?.kilometrage ?? 0,
    typeCarburant: vehicule?.typeCarburant ?? 'Diesel',
  })
  const [err, setErr] = useState({})
  const [enCours, setEnCours] = useState(false)
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value })

  const valider = () => {
    const e = {}
    if (!f.immatriculation.trim()) e.immatriculation = 'Champ obligatoire'
    if (!f.marque.trim()) e.marque = 'Champ obligatoire'
    if (!f.modele.trim()) e.modele = 'Champ obligatoire'
    if (!(+f.annee >= 1950 && +f.annee <= ANNEE_MAX)) e.annee = `Entre 1950 et ${ANNEE_MAX}`
    if (f.kilometrage === '' || +f.kilometrage < 0) e.kilometrage = 'Valeur invalide'
    else if (edition && +f.kilometrage < vehicule.kilometrage)
      e.kilometrage = `Ne peut pas être inférieur à ${km(vehicule.kilometrage)}`
    return e
  }

  const envoyer = async (ev) => {
    ev.preventDefault()
    const e = valider()
    setErr(e)
    if (Object.keys(e).length) return
    setEnCours(true)
    const data = {
      immatriculation: f.immatriculation.trim(),
      marque: f.marque.trim(),
      modele: f.modele.trim(),
      annee: +f.annee,
      kilometrage: +f.kilometrage,
      typeCarburant: f.typeCarburant,
    }
    try {
      const r = edition ? await api.modifier(vehicule.id, data) : await api.creer(data)
      onSaved(r)
    } catch (x) {
      // Erreur du backend : sous le champ si elle parle de l'immatriculation, sinon en haut
      setErr(/immatriculation/i.test(x.message) ? { immatriculation: x.message } : { global: x.message })
      setEnCours(false)
    }
  }

  return (
    <Modal onClose={onClose}>
      <form onSubmit={envoyer} noValidate>
        <h2 className="text-2xl font-bold">
          {edition ? `Modifier le véhicule ${vehicule.immatriculation}` : 'Ajouter un véhicule'}
        </h2>
        <p className="mb-5 text-sm text-slate-500">
          {edition ? 'Mettez à jour les informations du véhicule' : "Les champs marqués d'une étoile sont obligatoires"}
        </p>
        {err.global && <Alerte>{err.global}</Alerte>}

        <div className="grid grid-cols-2 gap-4">
          <Field label="Immatriculation" required error={err.immatriculation}>
            <input className={inp(err.immatriculation)} value={f.immatriculation} onChange={set('immatriculation')} />
          </Field>
          <Field label="Marque" required error={err.marque}>
            <input className={inp(err.marque)} value={f.marque} onChange={set('marque')} />
          </Field>
          <Field label="Modèle" required error={err.modele}>
            <input className={inp(err.modele)} value={f.modele} onChange={set('modele')} />
          </Field>
          <Field label="Année" required error={err.annee} hint={`Entre 1950 et ${ANNEE_MAX}`}>
            <input type="number" className={inp(err.annee)} value={f.annee} onChange={set('annee')} />
          </Field>
          <Field label="Kilométrage" error={err.kilometrage}
            hint={edition ? `Le kilométrage ne peut pas diminuer (actuel : ${km(vehicule.kilometrage)})` : undefined}>
            <input type="number" className={inp(err.kilometrage)} value={f.kilometrage} onChange={set('kilometrage')} />
          </Field>
          <Field label="Type de carburant" required>
            <select className={inp()} value={f.typeCarburant} onChange={set('typeCarburant')}>
              {CARBURANTS.map((c) => <option key={c}>{c}</option>)}
            </select>
          </Field>
        </div>

        {edition ? (
          <p className="mt-5 flex items-center gap-3 text-sm">
            <b>Statut</b> <Badge statut={vehicule.statut} />
            <span className="text-slate-500">Le statut se modifie depuis la fiche du véhicule</span>
          </p>
        ) : (
          <p className="mt-5 rounded-lg bg-sky-50 p-3 text-sm text-sky-900">
            Le statut sera <b>DISPONIBLE</b> à la création.
          </p>
        )}

        <div className="mt-6 flex justify-end gap-3">
          <button type="button" className={BTN.ghost} onClick={onClose}>Annuler</button>
          <button className={BTN.primary} disabled={enCours}>
            {edition ? 'Enregistrer les modifications' : 'Enregistrer'}
          </button>
        </div>
      </form>
    </Modal>
  )
}