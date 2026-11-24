export const formatMontant = (value) =>
  value == null
    ? "—"
    : new Intl.NumberFormat("fr-MA", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }).format(value) + " MAD";

export const formatNombre = (value, digits = 1) =>
  value == null
    ? "—"
    : new Intl.NumberFormat("fr-MA", {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
      }).format(value);

export const formatDate = (value) =>
  value ? value.split("-").reverse().join("/") : "—";
