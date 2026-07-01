import { escapeHtml } from "./util.js";

// Badge d'état. type: "ferme" | "degage" | "perturbe".
// Couleurs Figma : fond vif (400), texte + bordure sombres (950).
export function Badge(type, text) {
  const styles = {
    ferme: "bg-ferme text-ferme-fond border-ferme-fond",
    degage: "bg-degage text-degage-fond border-degage-fond",
    perturbe: "bg-perturbe text-perturbe-fond border-perturbe-fond",
  };
  const cls = styles[type] || styles.ferme;
  return `
    <div class="inline-flex h-[29px] min-w-[136px] items-center justify-center border px-[10px] text-sm font-medium ${cls}">
      ${escapeHtml(text)}
    </div>`;
}
