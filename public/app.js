import { escapeHtml, cap, nettoyerLieu } from "./composants/util.js";
import { StatusBar, NavBar, Footer, BackData } from "./composants/chrome.js";
import { Badge } from "./composants/badge.js";
import { Direction } from "./composants/direction.js";
import { Position } from "./composants/position.js";
import { Info } from "./composants/info.js";
import { Card } from "./composants/card.js";
import { AutorouteBadge, AutorouteTitre } from "./composants/autoroute.js";
import { Car } from "./composants/car.js";

const AUTOROUTES = ["A1", "A3", "A4", "A6", "A10", "A13", "A14", "A86", "A104"];
const app = document.getElementById("app");

// Pile de navigation (pour le bouton Retour).
let pile = [];
let rafraichir = null; // timer de la vue détail

function aller(vue, params) {
  pile.push({ vue, params });
  rendre(vue, params);
}

function retour() {
  if (rafraichir) {
    clearInterval(rafraichir);
    rafraichir = null;
  }
  pile.pop();
  const courant = pile[pile.length - 1] || { vue: "accueil" };
  rendre(courant.vue, courant.params);
}

function rendre(vue, params) {
  if (rafraichir && vue !== "detail") {
    clearInterval(rafraichir);
    rafraichir = null;
  }
  if (vue === "accueil") viewAccueil();
  else if (vue === "choix") viewChoix();
  else if (vue === "detail") viewDetail(params);
}

// --- Écran Accueil ---
function viewAccueil() {
  app.innerHTML = `
    ${StatusBar()}
    ${NavBar()}
    <div class="flex flex-1 flex-col px-5">
      <header class="flex flex-col gap-4 py-6">
        <h1 class="text-[32px] font-bold leading-tight text-texte">Ta route est-elle ouverte, ou c'est mort ?</h1>
        <p class="text-[16px] text-muted">Visualisez les fermetures d'autoroutes IDF en direct. Mis à jour automatiquement.</p>
      </header>
      <div class="flex flex-col gap-[10px]">
        ${Card({ titre: "Autoroute", desc: "Visualise les autoroutes", image: "autoroutes-idf.png", nav: "choix" })}
        ${Card({ titre: "Periferique", desc: "Visualise le trafic du périf", image: "peripherique.png", nav: "periph" })}
      </div>
    </div>
    ${Footer()}`;
}

// --- Écran Choix autoroute ---
function viewChoix() {
  const grille = AUTOROUTES.map(AutorouteBadge).join("");
  app.innerHTML = `
    ${StatusBar()}
    ${NavBar()}
    <div class="flex flex-1 flex-col px-5">
      <header class="flex flex-col gap-4 py-6">
        <h1 class="text-[32px] font-bold leading-tight text-texte">Choisis ton autoroute.</h1>
        <p class="text-[16px] text-muted">Sélectionnez-la pour afficher son état actuel.</p>
      </header>
      <div class="grid grid-cols-3 gap-5">${grille}</div>
    </div>
    <div class="flex justify-center py-8">
      <button data-nav="back" class="text-sm text-muted transition-colors hover:text-texte">Retour</button>
    </div>`;
}

// --- Écran Détail (async) ---
function viewDetail(autoroute) {
  app.innerHTML = `
    ${StatusBar()}
    ${NavBar()}
    <div class="flex flex-1 items-center justify-center py-20">
      <span class="text-muted">Chargement…</span>
    </div>`;

  charger(autoroute);
  if (rafraichir) clearInterval(rafraichir);
  rafraichir = setInterval(() => charger(autoroute), 5 * 60 * 1000);
}

function charger(autoroute) {
  fetch(`index.php?autoroute=${encodeURIComponent(autoroute)}`)
    .then((r) => r.json())
    .then((data) => afficherDetail(autoroute, data))
    .catch(() => {
      app.innerHTML = `
        ${StatusBar()}${NavBar()}
        <div class="flex flex-1 flex-col items-center justify-center gap-6 px-5 py-20 text-center">
          <p class="text-[20px] text-texte">Erreur de connexion.</p>
          <p class="text-sm text-muted">On réessaie dans 5 minutes.</p>
          <button data-nav="back" class="text-sm text-muted hover:text-texte">Retour</button>
        </div>`;
    });
}

function afficherDetail(autoroute, data) {
  if (data.error) {
    app.innerHTML = `
      ${StatusBar()}${NavBar()}
      <div class="flex flex-1 flex-col items-center justify-center gap-6 px-5 py-20 text-center">
        <p class="text-[20px] text-texte">${escapeHtml(data.error)}</p>
        <button data-nav="back" class="text-sm text-muted hover:text-texte">Retour</button>
      </div>`;
    return;
  }

  const nom = autoroute === "PERIPH" ? "Périph" : autoroute;
  const heure = new Date().toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" }).replace(":", "h");

  // --- Dégagé ---
  if (data.statut === "libre") {
    app.innerHTML = `
      ${StatusBar()}${NavBar()}
      <div class="flex flex-1 flex-col items-center px-5">
        <div class="flex flex-col items-center gap-2 py-10">
          ${AutorouteTitre(nom)}
          ${Badge("degage", "Dégagé")}
        </div>
        <p class="mt-8 text-center text-[20px] leading-relaxed text-texte">Tout roule !<br>Aucune fermeture signalée</p>
        <div class="mt-8">${Car()}</div>
      </div>
      ${BackData(heure)}`;
    return;
  }

  // --- Fermé / Perturbé ---
  const ferme = data.statut === "ferme";
  const type = ferme ? "ferme" : "perturbe";
  const nbFerme = data.incidents.filter((i) => i.gravite >= 4).length;
  const libelle = ferme
    ? `${nbFerme} fermeture${nbFerme > 1 ? "s" : ""}`
    : "Perturbé";

  const blocs = data.incidents.map(incidentBloc).join(
    `<div class="my-6 border-t border-dashed border-muted2"></div>`
  );

  app.innerHTML = `
    ${StatusBar()}${NavBar()}
    <div class="flex flex-1 flex-col px-5">
      <div class="flex flex-col items-center gap-2 py-8">
        ${AutorouteTitre(nom)}
        ${Badge(type, libelle)}
      </div>
      <div class="flex flex-col gap-4">${blocs}</div>
    </div>
    ${BackData(heure)}`;
}

// Un bloc incident (Direction + lieu + infos selon gravité).
function incidentBloc(inc) {
  let infos;
  if (inc.gravite >= 4) {
    const debut = "Fermé depuis le " + formatDate(inc.debut);
    const reouverture = inc.fin
      ? "Réouverture prévue le " + formatDate(inc.fin) + dansCombien(inc.fin)
      : "Réouverture non communiquée";
    infos = Info("Durée :", debut) + Info("Réouverture :", reouverture);
  } else {
    infos =
      Info("Cause :", inc.description || "Perturbation") +
      Info("Signalé à :", formatHeure(inc.debut));
  }

  const lieu = nettoyerLieu(inc.from);

  return `
    <div class="flex flex-col gap-4">
      ${Direction(inc.direction)}
      ${lieu ? Position(lieu) : ""}
      ${infos}
    </div>`;
}

// --- Dates ---
function formatDate(iso) {
  if (!iso) return "date inconnue";
  const d = new Date(iso);
  return (
    d.toLocaleDateString("fr-FR", { day: "numeric", month: "long" }) +
    " à " +
    d.toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" })
  );
}

function formatHeure(iso) {
  if (!iso) return "—";
  return new Date(iso).toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" });
}

function dansCombien(finIso) {
  const ms = new Date(finIso) - Date.now();
  if (ms <= 0) return "";
  const h = Math.round(ms / 3600000);
  return h >= 1 ? ` (dans : ${h}h)` : "";
}

// --- Navigation par délégation d'événements ---
app.addEventListener("click", (e) => {
  const nav = e.target.closest("[data-nav]");
  if (nav) {
    const cible = nav.dataset.nav;
    if (cible === "back") retour();
    else if (cible === "choix") aller("choix");
    else if (cible === "periph") aller("detail", "PERIPH");
    else if (cible === "accueil") aller("accueil");
    return;
  }
  const btn = e.target.closest("[data-autoroute]");
  if (btn) aller("detail", btn.dataset.autoroute);
});

// Démarrage.
aller("accueil");
