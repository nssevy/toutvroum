import { escapeHtml } from "./util.js";

// Carte d'accueil cliquable (Autoroute / Périphérique).
// nav = cible de navigation (data-nav).
export function Card({ titre, desc, image, nav }) {
  return `
    <a data-nav="${escapeHtml(nav)}" class="flex cursor-pointer flex-col items-center gap-10 border border-bordure bg-carte p-5 transition-colors hover:bg-carte/70">
      <div class="flex flex-col items-center gap-[5px]">
        <span class="text-[24px] text-texte">${escapeHtml(titre)}</span>
        <span class="text-[16px] text-muted">${escapeHtml(desc)}</span>
      </div>
      <img src="assets/${escapeHtml(image)}" class="h-[150px] w-[250px] object-contain" alt="" aria-hidden="true">
    </a>`;
}
