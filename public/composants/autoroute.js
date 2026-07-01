import { escapeHtml } from "./util.js";

// Badge autoroute carré (grille de choix). Cliquable -> ouvre le détail.
export function AutorouteBadge(nom) {
  return `
    <button data-autoroute="${escapeHtml(nom)}" class="flex h-[78px] items-center justify-center bg-carte text-[28px] font-bold text-texte transition-colors hover:bg-carte/70">
      ${escapeHtml(nom)}
    </button>`;
}

// Grand nom d'autoroute en tête d'écran détail.
export function AutorouteTitre(nom) {
  return `<h1 class="text-[88px] font-bold leading-none text-texte">${escapeHtml(nom)}</h1>`;
}
