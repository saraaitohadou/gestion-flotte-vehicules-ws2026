import { STATUTS, permisEtat } from "../utils.js";

export function StatusBadge({ statut }) {
  const s = STATUTS[statut] ?? { label: statut, tone: "mute" };
  return <span className={`badge badge-${s.tone}`}>{s.label}</span>;
}

export function PermisBadge({ date }) {
  const e = permisEtat(date);
  return <span className={`badge badge-${e.tone}`}>{e.label}</span>;
}
