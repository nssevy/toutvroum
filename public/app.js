let autorouteActive = null;
let timer = null;

function chercher(autoroute) {
  document.querySelectorAll(".boutons button").forEach((btn) => {
    btn.classList.remove("active");
    if (
      btn.textContent === autoroute ||
      (autoroute === "PERIPH" && btn.textContent === "Périph")
    ) {
      btn.classList.add("active");
    }
  });

  autorouteActive = autoroute;

  if (timer) clearInterval(timer);

  fetchIncidents(autoroute);
  timer = setInterval(() => fetchIncidents(autoroute), 5 * 60 * 1000);
}

function fetchIncidents(autoroute) {
  const resultat = document.getElementById("resultat");
  resultat.classList.remove("hidden");
  resultat.innerHTML = '<p class="loading">Chargement...</p>';

  fetch(`index.php?autoroute=${autoroute}`)
    .then((res) => res.json())
    .then((data) => afficher(data))
    .catch(() => {
      resultat.innerHTML =
        '<p class="erreur">Erreur de connexion, on réessaie dans 5 minutes.</p>';
    });
}

function afficher(data) {
  const resultat = document.getElementById("resultat");

  if (data.error) {
    resultat.innerHTML = `<p class="erreur">${data.error}</p>`;
    return;
  }

  // "A3" -> "l'A3" / "PERIPH" -> "le Périph"
  const nom = data.autoroute === "PERIPH" ? "le Périph" : `l'${data.autoroute}`;
  const masculin = data.autoroute === "PERIPH";
  const e = masculin ? "" : "e";

  let html;
  const statut = data.statut;

  if (statut === "libre") {
    html = `<div class="statut libre">✅ ${cap(
      nom
    )} est dégagé${e}, aucune perturbation signalée.</div>`;
  } else {
    const nbFerme = data.incidents.filter((i) => i.gravite >= 4).length;
    const n = data.incidents.length;

    if (statut === "ferme") {
      html = `<div class="statut ferme">🔴 ${cap(
        nom
      )} fermé${e} — ${nbFerme} fermeture${nbFerme > 1 ? "s" : ""}</div>`;
    } else {
      html = `<div class="statut perturbe">🟠 ${cap(
        nom
      )} perturbé${e} — ${n} incident${n > 1 ? "s" : ""}</div>`;
    }

    html += '<div class="incidents">';
    data.incidents.forEach((inc) => {
      const from = nettoyerLieu(inc.from);
      const ferme = inc.gravite >= 4;
      const pastille = ferme ? "🔴" : "🟠";

      // Titre = cause (Route fermée / Accident / Voie 1 fermée...) + direction si connue
      const cause = inc.description || "Perturbation";
      const titre = inc.direction
        ? `${pastille} ${cause} — ${inc.direction}`
        : `${pastille} ${cause}`;

      const trajet = from ? `Au niveau de ${from}` : "";

      const depuis = formatDate(inc.debut);
      let meta;
      if (ferme) {
        const fin = inc.fin
          ? `réouverture prévue le ${formatDate(inc.fin)}`
          : "réouverture non communiquée";
        meta = `Fermé depuis le ${depuis} — ${fin}.`;
      } else {
        meta = `Signalé depuis le ${depuis}.`;
      }

      html += `
                <div class="incident">
                    <p class="incident-titre">${titre}</p>
                    ${trajet ? `<p>${trajet}</p>` : ""}
                    <p class="incident-meta">${meta}</p>
                </div>
            `;
    });
    html += "</div>";
  }

  const now = new Date().toLocaleTimeString("fr-FR", {
    hour: "2-digit",
    minute: "2-digit",
  });
  html += `<p class="maj">Dernière mise à jour : ${now}</p>`;

  resultat.innerHTML = html;
}

// Majuscule sur la première lettre
function cap(s) {
  return s.charAt(0).toUpperCase() + s.slice(1);
}

// "Romainville - D40 - D117 (D36BIS)" -> "Romainville"
function nettoyerLieu(lieu) {
  if (!lieu) return "";
  return lieu
    .replace(/\s*\([^)]*\)/g, "") // retire les (D36BIS)
    .split(" - ")[0] // garde le 1er segment (la ville)
    .trim();
}

function formatDate(iso) {
  if (!iso) return "date inconnue";
  return new Date(iso).toLocaleString("fr-FR", {
    day: "2-digit",
    month: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  });
}
