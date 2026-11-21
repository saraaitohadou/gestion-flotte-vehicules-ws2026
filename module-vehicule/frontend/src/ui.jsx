export const STATUTS = {
  DISPONIBLE: 'bg-green-100 text-green-700',
  EN_MISSION: 'bg-blue-100 text-blue-700',
  EN_MAINTENANCE: 'bg-orange-100 text-orange-700',
  HORS_SERVICE: 'bg-red-100 text-red-700',
}
export const CARBURANTS = ['Diesel', 'Essence', 'Hybride', 'Électrique']

export const km = (n) => `${Number(n).toLocaleString('fr-FR')} km`

export const BTN = {
  primary: 'rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark disabled:opacity-50',
  ghost: 'rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50',
  danger: 'rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700',
}

export const inp = (erreur) =>
  `mt-1 w-full rounded-lg border bg-white px-3 py-2 text-sm font-normal outline-none ${
    erreur ? 'border-red-500' : 'border-slate-300 focus:border-brand'
  }`

export function Badge({ statut }) {
  return (
    <span className={`rounded-full px-2.5 py-0.5 text-[11px] font-bold ${STATUTS[statut]}`}>
      {statut}
    </span>
  )
}

export function Alerte({ ok, children }) {
  return (
    <p className={`mb-4 rounded-lg p-3 text-sm ${ok ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>
      {children}
    </p>
  )
}

export function Modal({ children, onClose }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" onClick={onClose}>
      <div className="w-full max-w-lg rounded-xl bg-white p-7 shadow-xl" onClick={(e) => e.stopPropagation()}>
        {children}
      </div>
    </div>
  )
}

export function Field({ label, required, hint, error, children }) {
  return (
    <label className="block text-sm font-medium text-slate-700">
      {label}
      {required && <span className="text-red-500"> *</span>}
      {children}
      {error ? (
        <span className="mt-1 block text-xs font-normal text-red-600">{error}</span>
      ) : (
        hint && <span className="mt-1 block text-xs font-normal text-slate-500">{hint}</span>
      )}
    </label>
  )
}

export function SupprimerModal({ v, onCancel, onConfirm }) {
  return (
    <Modal onClose={onCancel}>
      <div className="text-center">
        <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-red-100 text-xl">🗑️</div>
        <h2 className="text-xl font-bold">Supprimer ce véhicule ?</h2>
        <p className="mt-2 text-sm text-slate-600">
          <b>{v.immatriculation}</b> – {v.marque} {v.modele}.<br />
          Cette action est définitive.
        </p>
        <div className="mt-5 flex justify-center gap-3">
          <button className={BTN.ghost} onClick={onCancel}>Annuler</button>
          <button className={BTN.danger} onClick={onConfirm}>Supprimer</button>
        </div>
      </div>
    </Modal>
  )
}
