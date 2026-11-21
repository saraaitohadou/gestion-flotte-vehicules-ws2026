import { STATUTS, TYPES } from "../utils.js";

export function StatusBadge({ statut }) {
  const s = STATUTS[statut] ?? { label: statut, tone: "mute" };
  return <span className={`badge badge-${s.tone}`}>{s.label}</span>;
}

export function TypeBadge({ type }) {
  const t = TYPES[type] ?? { label: type, tone: "mute" };
  return <span className={`badge badge-${t.tone}`}>{t.label}</span>;
}