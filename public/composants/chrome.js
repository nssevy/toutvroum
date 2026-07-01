// Barre de statut iOS (mock), barre de navigation, pied de page.

export function StatusBar() {
  return `
    <div class="flex h-[50px] items-center justify-between px-6 text-sm text-texte">
      <span class="font-semibold">10:28</span>
      <div class="flex items-center gap-1.5">
        <span aria-hidden="true">▂▄▆</span>
        <span aria-hidden="true">􀙇</span>
        <span class="inline-block h-[11px] w-[22px] rounded-[3px] border border-texte/60 p-[1px]">
          <span class="block h-full w-full rounded-[1px] bg-texte"></span>
        </span>
      </div>
    </div>`;
}

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
