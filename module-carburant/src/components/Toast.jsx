import { CheckCircle2, CircleAlert, X } from "lucide-react";

export default function Toast({ toast, onClose }) {
  if (!toast) return null;
  return (
    <div className={`toast ${toast.type === "error" ? "toast-error" : "toast-success"}`} role={toast.type === "error" ? "alert" : "status"}>
      {toast.type === "error" ? <CircleAlert size={20} /> : <CheckCircle2 size={20} />}
      <span>{toast.message}</span>
      <button type="button" onClick={onClose} aria-label="Fermer le message"><X size={16} /></button>
    </div>
  );
}
