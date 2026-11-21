const BASE = import.meta.env.VITE_API_URL ?? '/api'

async function request(path, options = {}) {
  const res = await fetch(BASE + path, {
    headers: { 'Content-Type': 'application/json' },
    ...options,
  })
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