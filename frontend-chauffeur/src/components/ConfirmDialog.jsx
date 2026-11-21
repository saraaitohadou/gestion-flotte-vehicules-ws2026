import { useEffect, useRef } from "react";

export default function ConfirmDialog({ title, children, confirmLabel = "Confirmer", busy, onConfirm, onCancel }) {
  const ref = useRef(null);

  useEffect(() => {
    const el = ref.current;
    el.showModal();
    return () => el.open && el.close();
  }, []);

  return (
    <dialog ref={ref} className="dialog" onCancel={onCancel} onClick={(e) => e.target === ref.current && onCancel()}>
      <h2>{title}</h2>
      <div className="dialog-body">{children}</div>
      <div className="dialog-actions">
        <button className="btn" onClick={onCancel} disabled={busy}>Annuler</button>
        <button className="btn btn-danger" onClick={onConfirm} disabled={busy}>
          {busy ? "Un instant..." : confirmLabel}
        </button>
      </div>
    </dialog>
  );
}
