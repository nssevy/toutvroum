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
  const fermee = masculin ? "fermé" : "fermée";

  let html;
  const n = data.incidents.length;

  if (n === 0) {
    html = `<div class="statut libre">✅ ${cap(nom)} est dégagé${
      masculin ? "" : "e"
    }, aucune fermeture signalée.</div>`;
  } else {
    html = `<div class="statut ferme">🚫 ${cap(
      nom
    )} ${fermee} — ${n} fermeture${n > 1 ? "s" : ""}</div>`;

    html += '<div class="incidents">';
    data.incidents.forEach((inc) => {
      const from = nettoyerLieu(inc.from);
      const to = nettoyerLieu(inc.to);
      const debut = formatDate(inc.debut);
      const fin = inc.fin
        ? `réouverture prévue le ${formatDate(inc.fin)}`
        : "réouverture non communiquée";

      const trajet =
        from && to && from !== to
          ? `entre ${from} et ${to}`
          : from || to
          ? `au niveau de ${from || to}`
          : "";

      // Direction = info prioritaire (trajet A → B de la chaussée fermée)
      const titre = inc.direction ? `🚫 ${inc.direction}` : "🚫 Fermeture";

      html += `
                <div class="incident">
                    <p class="incident-titre">${titre}</p>
                    ${trajet ? `<p>${cap(trajet)}</p>` : ""}
                    <p class="incident-meta">Fermé depuis le ${debut} — ${fin}.</p>
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
