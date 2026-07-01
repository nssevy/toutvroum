// Utilitaires partagés par les composants.

// Échappe le HTML (les libellés viennent de l'API DiRIF).
export function escapeHtml(s) {
  return String(s ?? "").replace(/[&<>"']/g, (c) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#39;",
  }[c]));
}

// Majuscule sur la première lettre.
export function cap(s) {
  s = String(s ?? "");
  return s.charAt(0).toUpperCase() + s.slice(1);
}

// "Romainville - D40 (D36BIS)" -> "Romainville"
export function nettoyerLieu(lieu) {
  if (!lieu) return "";
  return lieu.replace(/\s*\([^)]*\)/g, "").split(" - ")[0].trim();
}
