import { useEffect, useState } from 'react'
import { Link, NavLink, Route, Routes } from 'react-router-dom'
import { vehicules as api } from './api'
import { Alerte, Badge, STATUTS, km } from './ui'
import Vehicules from './pages/Vehicules'
import VehiculeDetail from './pages/VehiculeDetail'

const MENU = [
  ['Tableau de bord', '/'],
  ['Liste des véhicules', '/vehicules'],
]

const LIBELLES = {
  DISPONIBLE: 'Disponible',
  EN_MISSION: 'En mission',
  EN_MAINTENANCE: 'En maintenance',
  HORS_SERVICE: 'Hors service',
}
const BARRES = {
  DISPONIBLE: 'bg-green-500',
  EN_MISSION: 'bg-blue-500',
  EN_MAINTENANCE: 'bg-orange-500',
  HORS_SERVICE: 'bg-red-500',
}
const BORDURES = {
  DISPONIBLE: 'border-l-green-500',
  EN_MISSION: 'border-l-blue-500',
  EN_MAINTENANCE: 'border-l-orange-500',
  HORS_SERVICE: 'border-l-red-500',
}

const carte = 'rounded-xl bg-white p-5 shadow-sm'

function Barre({ libelle, valeur, total, couleur }) {
  const pct = total ? Math.round((valeur / total) * 100) : 0
  return (
    <div className="mb-3 last:mb-0">
      <div className="mb-1 flex justify-between text-sm">
        <span className="font-medium">{libelle}</span>
        <span className="text-slate-500"><b className="text-slate-900">{valeur}</b> ({pct} %)</span>
      </div>
      <div className="h-2.5 overflow-hidden rounded-full bg-slate-100">
        <div className={`h-full rounded-full ${couleur}`} style={{ width: `${pct}%` }} />
      </div>
    </div>
  )
}

function Dashboard() {
  const [liste, setListe] = useState(null)
  const [erreur, setErreur] = useState('')

  useEffect(() => {
    api.lister().then(setListe).catch((e) => setErreur(e.message))
  }, [])

  if (erreur) return <Alerte>{erreur}</Alerte>
  if (!liste) return <p className="text-slate-500">Chargement du tableau de bord...</p>

  const total = liste.length
  const compte = (s) => liste.filter((v) => v.statut === s).length
  const kmTotal = liste.reduce((somme, v) => somme + Number(v.kilometrage), 0)
  const kmMoyen = total ? Math.round(kmTotal / total) : 0
  const tauxDispo = total ? Math.round((compte('DISPONIBLE') / total) * 100) : 0

  const parCarburant = liste.reduce((acc, v) => {
    acc[v.typeCarburant] = (acc[v.typeCarburant] ?? 0) + 1
    return acc
  }, {})

  const aSurveiller = liste.filter((v) => v.statut === 'EN_MAINTENANCE' || v.statut === 'HORS_SERVICE')
  const recents = [...liste].sort((a, b) => b.id - a.id).slice(0, 5)

  return (
    <>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold">Tableau de bord</h1>
          <p className="text-sm text-slate-500">Vue d'ensemble du parc automobile</p>
        </div>
        <Link to="/vehicules" className="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
          Gérer les véhicules
        </Link>
      </div>

      {/* Cartes de synthèse */}
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
        <div className={`${carte} border-l-4 border-l-slate-800`}>
          <p className="text-xs font-semibold uppercase text-slate-500">Total véhicules</p>
          <p className="mt-1 text-3xl font-bold">{total}</p>
        </div>
        {Object.keys(STATUTS).map((s) => (
          <div key={s} className={`${carte} border-l-4 ${BORDURES[s]}`}>
            <p className="text-xs font-semibold uppercase text-slate-500">{LIBELLES[s]}</p>
            <p className="mt-1 text-3xl font-bold">{compte(s)}</p>
          </div>
        ))}
      </div>

      {/* Répartition et indicateurs */}
      <div className="mt-4 grid gap-4 lg:grid-cols-3">
        <div className={`${carte} lg:col-span-2`}>
          <h2 className="mb-4 font-bold">Répartition par statut</h2>
          {Object.keys(STATUTS).map((s) => (
            <Barre key={s} libelle={LIBELLES[s]} valeur={compte(s)} total={total} couleur={BARRES[s]} />
          ))}
        </div>

        <div className={carte}>
          <h2 className="mb-4 font-bold">Indicateurs</h2>
          <dl className="space-y-3 text-sm">
            <div className="flex justify-between"><dt className="text-slate-500">Taux de disponibilité</dt><dd className="font-bold">{tauxDispo} %</dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Kilométrage total</dt><dd className="font-bold">{km(kmTotal)}</dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Kilométrage moyen</dt><dd className="font-bold">{km(kmMoyen)}</dd></div>
          </dl>
          <h3 className="mb-3 mt-5 text-sm font-bold">Par type de carburant</h3>
          {Object.entries(parCarburant).map(([c, n]) => (
            <Barre key={c} libelle={c} valeur={n} total={total} couleur="bg-brand" />
          ))}
          {total === 0 && <p className="text-sm text-slate-500">Aucune donnée</p>}
        </div>
      </div>

      {/* Listes */}
      <div className="mt-4 grid gap-4 lg:grid-cols-2">
        <div className={carte}>
          <h2 className="mb-3 font-bold">Véhicules à surveiller</h2>
          {aSurveiller.length === 0 ? (
            <p className="text-sm text-slate-500">Aucun véhicule en maintenance ou hors service.</p>
          ) : (
            <ul className="divide-y divide-slate-100">
              {aSurveiller.map((v) => (
                <li key={v.id} className="flex items-center justify-between py-2.5 text-sm">
                  <Link to={`/vehicules/${v.id}`} className="hover:underline">
                    <b>{v.immatriculation}</b>
                    <span className="ml-2 text-slate-500">{v.marque} {v.modele}</span>
                  </Link>
                  <Badge statut={v.statut} />
                </li>
              ))}
            </ul>
          )}
        </div>

        <div className={carte}>
          <div className="mb-3 flex items-center justify-between">
            <h2 className="font-bold">Derniers véhicules ajoutés</h2>
            <Link to="/vehicules" className="text-sm text-brand hover:underline">Voir la liste</Link>
          </div>
          {recents.length === 0 ? (
            <p className="text-sm text-slate-500">Aucun véhicule enregistré.</p>
          ) : (
            <ul className="divide-y divide-slate-100">
              {recents.map((v) => (
                <li key={v.id} className="flex items-center justify-between py-2.5 text-sm">
                  <Link to={`/vehicules/${v.id}`} className="hover:underline">
                    <b>{v.immatriculation}</b>
                    <span className="ml-2 text-slate-500">{v.marque} {v.modele}</span>
                  </Link>
                  <span className="mr-3 ml-auto text-slate-500">{km(v.kilometrage)}</span>
                  <Badge statut={v.statut} />
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </>
  )
}

export default function App() {
  return (
    <div className="flex min-h-screen">
      <aside className="sticky top-0 flex h-screen w-60 shrink-0 flex-col bg-[#15233a] px-4 py-6 text-white">
        <div className="mb-8 flex items-center gap-3 px-2">
          <span className="grid h-10 w-10 place-items-center rounded-[10px] bg-[#f2b33d] text-xl font-extrabold text-[#15233a]">F</span>
          <div>
            <strong className="block text-lg leading-tight">Flotte</strong>
            <span className="text-xs text-[#a9b6c9]">Module véhicules</span>
          </div>
        </div>
        <nav className="flex flex-col gap-1">
          {MENU.map(([nom, to]) => (
            <NavLink
              key={nom}
              to={to}
              end={to === '/'}
              className={({ isActive }) =>
                `rounded-lg px-3 py-2.5 text-sm ${isActive ? 'bg-white font-bold text-[#15233a]' : 'font-medium text-[#d3dcea] hover:bg-white/10'}`
              }
            >
              {nom}
            </NavLink>
          ))}
        </nav>
      </aside>
      <div className="min-w-0 flex-1">
        <header className="flex h-14 items-center justify-between border-b bg-white px-6">
          <span className="font-semibold">Module Véhicule</span>
          <div className="flex items-center gap-2 text-sm">
            <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs">Gestionnaire</span>
            Monya O.
            <span className="flex h-8 w-8 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-700">MO</span>
          </div>
        </header>
        <main className="mx-auto max-w-6xl p-6">
          <Routes>
            <Route path="/" element={<Dashboard />} />
            <Route path="/vehicules" element={<Vehicules />} />
            <Route path="/vehicules/:id" element={<VehiculeDetail />} />
          </Routes>
        </main>
      </div>
    </div>
  )
}