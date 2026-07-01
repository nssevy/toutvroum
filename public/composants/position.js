import { escapeHtml } from "./util.js";

// Pin de localisation orange : "Au niveau de [lieu]".
export function Position(lieu) {
  return `
    <div class="flex items-center gap-[2px]">
      <img src="assets/map-pin.svg" class="size-6" alt="" aria-hidden="true">
      <span class="text-[20px] font-medium text-pin">Au niveau de ${escapeHtml(lieu)}</span>
    </div>`;
}
