const urlParams = new URLSearchParams(window.location.search)
const urlToken = urlParams.get('token')
if (urlToken) {
  localStorage.setItem('flotte.token', urlToken)
  window.history.replaceState({}, document.title, window.location.pathname)
}

const BASE = import.meta.env.VITE_API_URL ?? '/api'
const AUTH_URL = import.meta.env.VITE_AUTH_URL ?? 'http://localhost:8002/api/auth/login'

async function getToken() {
  let token = localStorage.getItem('flotte.token')
  if (token) return token

  const res = await fetch(AUTH_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      username: import.meta.env.VITE_ADMIN_USERNAME ?? 'admin',
      password: import.meta.env.VITE_ADMIN_PASSWORD ?? 'adminpass',
    }),
  })

  if (!res.ok) {
    throw new Error(`Erreur d'authentification (${res.status})`)
  }

  const data = await res.json()
  token = data.access_token
  localStorage.setItem('flotte.token', token)
  return token
}

async function request(path, options = {}) {
  let token = await getToken()

  const doFetch = (jwt) =>
    fetch(BASE + path, {
      ...options,
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${jwt}`,
        ...options.headers,
      },
    })

  let res = await doFetch(token)

  // Si le token a expiré (401), on le supprime et on retente une fois
  if (res.status === 401) {
    localStorage.removeItem('flotte.token')
    token = await getToken()
    res = await doFetch(token)
  }

  const data = res.status === 204 ? null : await res.json().catch(() => null)
  if (!res.ok) throw new Error(data?.erreur ?? `Erreur ${res.status}`)
  return data
}

const send = (method, body) => ({ method, body: JSON.stringify(body) })

export const vehicules = {
  lister: () => request('/vehicules'),
  obtenir: (id) => request(`/vehicules/${id}`),
  creer: (v) => request('/vehicules', send('POST', v)),
  modifier: (id, v) => request(`/vehicules/${id}`, send('PUT', v)),
  supprimer: (id) => request(`/vehicules/${id}`, { method: 'DELETE' }),
  changerStatut: (id, statut) =>
    request(`/vehicules/${id}/statut`, send('PATCH', { statut })),
  disponibilite: (id) => request(`/vehicules/${id}/disponibilite`),
  majKilometrage: (id, kilometrage) =>
    request(`/vehicules/${id}/kilometrage`, send('PATCH', { kilometrage })),
}