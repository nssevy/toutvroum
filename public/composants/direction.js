import { escapeHtml } from "./util.js";

// Sens de circulation : "[origine] ——→ [destination]".
// Accepte la string direction du back :
//   "Paris → Bordeaux"  -> origine=Paris, destination=Bordeaux
//   "vers Bordeaux"     -> origine="Direction", destination="Bordeaux"
//   "sens intérieur" / "les deux sens" -> origine="Direction", destination=tel quel
export function Direction(direction) {
  let gauche = "Direction";
  let droite = direction || "";
  if (direction && direction.includes("→")) {
    const [g, d] = direction.split("→");
    gauche = g.trim();
    droite = d.trim();
  } else if (direction && /^vers\s+/i.test(direction)) {
    droite = direction.replace(/^vers\s+/i, "");
  }

  return `
    <div class="flex items-center gap-3">
      <span class="whitespace-nowrap text-[28px] font-bold text-texte">${escapeHtml(gauche)}</span>
      <span class="flex flex-1 items-center">
        <span class="h-px flex-1 bg-texte"></span>
        <span class="h-0 w-0 border-y-[6px] border-l-[10px] border-y-transparent border-l-texte"></span>
      </span>
      <span class="whitespace-nowrap text-[28px] font-bold text-texte">${escapeHtml(droite)}</span>
    </div>`;
}
