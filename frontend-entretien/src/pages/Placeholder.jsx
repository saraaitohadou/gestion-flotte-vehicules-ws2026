// Page temporaire : remplacee par la vraie page dans les blocs suivants
export default function Placeholder({ titre }) {
  return (
    <>
      <div className="page-head"><h1>{titre}</h1></div>
      <div className="panel"><p className="muted">Page en construction.</p></div>
    </>
  );
}