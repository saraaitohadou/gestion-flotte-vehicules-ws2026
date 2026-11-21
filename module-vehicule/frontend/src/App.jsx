import { useEffect, useState } from 'react'
import { NavLink, Route, Routes } from 'react-router-dom'
import { vehicules as api } from './api'
import { STATUTS , BTN } from './ui'
import Vehicules from './pages/Vehicules'
import VehiculeDetail from './pages/VehiculeDetail'


const MENU = [
  ['Tableau de bord', '/'],
  ['Véhicules', '/vehicules'],
]

function Dashboard() {
  const [liste, setListe] = useState([])
  useEffect(() => { api.lister().then(setListe).catch(() => {}) }, [])
  return (
    <>
      <h1 className="text-2xl font-bold">Tableau de bord</h1>
      <p className="mb-6 text-sm text-slate-500">Vue d'ensemble du parc automobile</p>      
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        {Object.keys(STATUTS).map((s) => (
          <div key={s} className="rounded-xl bg-white p-5 shadow-sm">
            <p className="text-xs font-semibold text-slate-500">{s}</p>
            <p className="mt-1 text-3xl font-bold">{liste.filter((v) => v.statut === s).length}</p>
          </div>
        ))}
      </div>
    </>
  )
}


export default function App() {
  return (
    <div className="flex min-h-screen">
      <aside className="w-52 shrink-0 bg-navy text-slate-300">
        <div className="px-4 py-5 text-lg font-bold text-white">Flotte</div>
        <nav className="space-y-1 pr-3">
          {MENU.map(([nom, to]) => (
            <NavLink
              key={nom}
              to={to}
              end={to === '/'}
              className={({ isActive }) =>
                `block rounded-r-lg px-4 py-2 text-sm ${isActive ? 'bg-blue-600 font-medium text-white' : 'hover:bg-white/10'}`
              }
            >
              {nom}
            </NavLink>
          ))}
        </nav>
      </aside>
      <div className="min-w-0 flex-1">
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