// Barre de navigation, pied de page.

export function NavBar() {
  return `
    <div class="flex h-[35px] items-center justify-center">
      <span class="text-sm text-texte">ToutVroum</span>
    </div>`;
}

export function Footer() {
  return `
    <div class="flex h-[79px] items-center justify-center text-sm text-muted">
      Made by <span class="ml-1 font-semibold text-texte">@sevy</span>
    </div>`;
}

// Retour + horodatage bas de l'écran détail.
export function BackData(heure) {
  return `
    <div class="flex flex-col items-center gap-4 py-8">
      <button data-nav="back" class="text-sm text-muted transition-colors hover:text-texte">Retour</button>
      <span class="text-sm text-muted">Donnée à jour depuis ${heure}</span>
    </div>`;
}
