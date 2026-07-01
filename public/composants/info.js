import { escapeHtml } from "./util.js";

// Bloc info : libellé (gris) + valeur (blanc).
export function Info(label, valeur) {
  return `
    <div class="flex flex-col gap-[2px]">
      <span class="text-sm text-muted">${escapeHtml(label)}</span>
      <span class="text-[20px] text-texte">${escapeHtml(valeur)}</span>
    </div>`;
}
