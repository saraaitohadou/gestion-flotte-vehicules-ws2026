export default function KpiCard({ label, value, unit, tone, icon: Icon }) {
  return (
    <article className={`kpi-card ${tone === "danger" ? "kpi-danger" : ""}`}>
      {Icon && <div className="kpi-icon" aria-hidden="true"><Icon size={19} /></div>}
      <span className="kpi-label">{label}</span>
      <strong className="kpi-value">{value}{unit && <small>{unit}</small>}</strong>
    </article>
  );
}
