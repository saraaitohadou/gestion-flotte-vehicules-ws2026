import { useState } from 'react'
import { MODULES } from './config/modules'

// Styles visuels métier par module (sans aucune info technique)
const MODULE_STYLES = {
  vehicules: {
    icon: '🚗',
    label: 'Parc Automobile',
    bgIcon: 'bg-blue-50 text-blue-600',
    borderHover: 'hover:border-blue-400',
    badge: 'bg-blue-50 text-blue-700',
  },
  chauffeurs: {
    icon: '🪪',
    label: 'Conducteurs',
    bgIcon: 'bg-emerald-50 text-emerald-600',
    borderHover: 'hover:border-emerald-400',
    badge: 'bg-emerald-50 text-emerald-700',
  },
  carburant: {
    icon: '⛽',
    label: 'Consommation',
    bgIcon: 'bg-amber-50 text-amber-600',
    borderHover: 'hover:border-amber-400',
    badge: 'bg-amber-50 text-amber-700',
  },
  entretien: {
    icon: '🔧',
    label: 'Maintenance',
    bgIcon: 'bg-purple-50 text-purple-600',
    borderHover: 'hover:border-purple-400',
    badge: 'bg-purple-50 text-purple-700',
  },
}

export default function App() {
  const [token] = useState(() => {
    const params = new URLSearchParams(window.location.search)
    const urlToken = params.get('token')
    if (urlToken) {
      localStorage.setItem('flotte.token', urlToken)
      window.history.replaceState({}, document.title, window.location.pathname)
      return urlToken
    }
    return localStorage.getItem('flotte.token')
  })
  const handleLogout = () => {
    localStorage.removeItem('flotte.token')
    window.location.replace('http://localhost:5174/connexion?logout=1')
  }

  const ouvrirModule = (urlFrontend) => {
    const separator = urlFrontend.includes('?') ? '&' : '?'
    window.location.href = `${urlFrontend}${separator}token=${encodeURIComponent(token)}`
  }

  // ÉCRAN 1 : CONNEXION
  if (!token) {
    window.location.replace('http://localhost:5174/connexion')
    return null
  }

  // ÉCRAN 2 : TABLEAU DE BORD ADMINISTRATEUR
  return (
    <div className="min-h-screen bg-slate-50 flex flex-col">
      {/* Barre supérieure élégante */}
      <header className="bg-slate-900 text-white border-b border-slate-800 sticky top-0 z-10">
        <div className="max-w-6xl mx-auto px-6 py-4 flex justify-between items-center">
          <div className="flex items-center gap-3">
            <div className="w-9 h-9 rounded-lg bg-amber-400 text-slate-900 font-extrabold text-lg flex items-center justify-center shadow-sm">
              F
            </div>
            <div>
              <div className="flex items-center gap-2.5">
                <span className="text-lg font-bold tracking-tight">Flotte Manager</span>
                <span className="text-xs bg-slate-800 text-slate-300 border border-slate-700 px-2.5 py-0.5 rounded-full font-medium">
                  Espace Administration
                </span>
              </div>
            </div>
          </div>

          <div className="flex items-center gap-4">
            <div className="hidden sm:flex items-center gap-2 bg-slate-800/80 px-3 py-1.5 rounded-full border border-slate-700">
              <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" />
              <span className="text-xs text-slate-300">
                Administrateur · <strong className="text-white">admin</strong>
              </span>
            </div>
            <button
              onClick={handleLogout}
              className="text-sm font-medium bg-slate-800 hover:bg-red-600 text-slate-200 hover:text-white px-4 py-2 rounded-xl border border-slate-700 hover:border-red-600 transition cursor-pointer"
            >
              Déconnexion
            </button>
          </div>
        </div>
      </header>

      {/* Contenu principal */}
      <main className="flex-1 max-w-6xl w-full mx-auto py-12 px-6">
        <div className="mb-10">
          <p className="text-sm font-semibold text-blue-600 uppercase tracking-wider mb-1">
            Vue d&apos;ensemble
          </p>
          <h1 className="text-3xl font-extrabold text-slate-900 tracking-tight">
            Gestion de votre flotte
          </h1>
          <p className="text-slate-500 mt-2 max-w-2xl">
            Sélectionnez un espace de gestion ci-dessous pour piloter vos véhicules, conducteurs, consommations et opérations de maintenance.
          </p>
        </div>

        {/* Grille des 4 modules */}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          {MODULES.map((mod, index) => {
            const fallbackKeys = ['vehicules', 'chauffeurs', 'carburant', 'entretien']
            const style =
              MODULE_STYLES[mod.id] ||
              MODULE_STYLES[fallbackKeys[index % fallbackKeys.length]]

            return (
              <div
                key={mod.id}
                onClick={() => ouvrirModule(mod.urlFrontend)}
                className={`bg-white rounded-2xl p-7 shadow-sm hover:shadow-xl border border-slate-200/80 ${style.borderHover} cursor-pointer transition-all duration-200 hover:-translate-y-1 group flex flex-col justify-between`}
              >
                <div>
                  <div className="flex items-start justify-between mb-5">
                    <div
                      className={`w-12 h-12 rounded-xl ${style.bgIcon} flex items-center justify-center text-2xl shadow-xs`}
                    >
                      {style.icon}
                    </div>
                    <span
                      className={`text-xs font-semibold px-3 py-1 rounded-full ${style.badge}`}
                    >
                      {style.label}
                    </span>
                  </div>

                  <h2 className="text-xl font-bold text-slate-900 group-hover:text-blue-600 transition">
                    {mod.titre}
                  </h2>
                  <p className="text-sm text-slate-500 mt-2 leading-relaxed">
                    {mod.description}
                  </p>
                </div>

                <div className="mt-6 pt-4 border-t border-slate-100 flex items-center justify-between text-sm font-semibold text-slate-700 group-hover:text-blue-600 transition">
                  <span>Ouvrir le module</span>
                  <span className="transform group-hover:translate-x-1.5 transition-transform">
                    →
                  </span>
                </div>
              </div>
            )
          })}
        </div>
      </main>
    </div>
  )
}