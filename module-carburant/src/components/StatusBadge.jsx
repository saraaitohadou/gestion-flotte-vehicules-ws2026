export default function StatusBadge({ anomalie }) {
  return <span className={`status-badge ${anomalie ? "status-danger" : "status-ok"}`}>{anomalie ? "Alerte" : "Normal"}</span>;
}
