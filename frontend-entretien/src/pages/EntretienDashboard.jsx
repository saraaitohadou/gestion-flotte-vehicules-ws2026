import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { api } from "../api.js";
import { useAuth } from "../components/AuthContext.jsx";
import { ROLES } from "../roles.js";

export default function EntretienDashboard() {
  const { user } = useAuth();
  const estAdmin = user?.role === ROLES.ADMIN;
  const [stats, setStats] = useState(null);
  const [error, setError] = useState("");

  useEffect(() => {
    (async () => {
      try {
        const total = (r) => r?.meta?.total ?? r?.data?.length ?? 0;
        const [planifies, enCours, termines] = await Promise.all([
          api.list({ statut: "PLANIFIE", per_page: 1 }),
          api.list({ statut: "EN_COURS", per_page: 1 }),
          api.list({ statut: "TERMINE", per_page: 1 }),
        ]);
        let declarations = 0;
        if (estAdmin) {
          const r = await api.declarations({ statut: "DECLAREE" });
          declarations = r.data?.length ?? 0;
        }
        setStats({
          planifies: total(planifies),
          enCours: total(enCours),
          termines: total(termines),
          declarations,
        });
      } catch (e) {
        setError(e.message);
      }
    })();
  }, [estAdmin]);

  const cartes = stats && [
    { label: "Entretiens en cours", valeur: stats.enCours, to: "/entretien/liste?statut=EN_COURS", tone: "warn" },
    { label: "Entretiens planifies", valeur: stats.planifies, to: "/entretien/liste?statut=PLANIFIE", tone: "info" },
    ...(estAdmin ? [
      { label: "Declarations en attente", valeur: stats.declarations, to: "/entretien/declarations", tone: stats.declarations ? "warn" : "ok" },
    ] : []),
    { label: "Entretiens termines", valeur: stats.termines, to: "/entretien/liste?statut=TERMINE", tone: "ok" },
  ];

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Tableau de bord entretien</h1>
          <p className="muted">Vue d'ensemble de l'activite.</p>
        </div>
        {estAdmin && <Link className="btn btn-primary" to="/entretien/nouveau">Nouvel entretien</Link>}
      </div>

      {error && <p className="alert alert-bad" role="alert">{error}</p>}
      {!stats && !error && <p className="muted">Chargement...</p>}

      {cartes && (
        <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(220px, 1fr))", gap: 16 }}>
          {cartes.map((c) => (
            <Link key={c.label} to={c.to} className="panel" style={{ textDecoration: "none", color: "inherit", marginBottom: 0, borderLeft: `6px solid var(--${c.tone})` }}>
              <div className="muted small">{c.label}</div>
              <div style={{ fontSize: "2.2rem", fontWeight: 800 }}>{c.valeur}</div>
            </Link>
          ))}
        </div>
      )}
    </>
  );
}