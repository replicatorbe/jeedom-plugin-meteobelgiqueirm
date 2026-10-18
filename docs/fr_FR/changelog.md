# Changelog

## 0.4 — 18/10/2026

- Une vigilance terminée s'efface : libellé, type, texte, date de fin et
  prochaine vigilance restaient affichés sous un *Vigilance en cours* à 0
  (« Vigilance jaune — Brouillard » encore visible bien après le 06/10/2026).
  Le vide n'est écrit qu'une fois, pour ne pas réveiller les scénarios à
  chaque passage, et jamais après une lecture en échec.
- *Rafales* : quand l'IRM ne donne pas de rafale, la commande prend la vitesse
  du vent au lieu de garder la dernière rafale annoncée (50 km/h affichés
  depuis le 09/10/2026 par 10 km/h de vent).

## 0.3 — 13/10/2026

- Méthode `tvWeather()` pour l'affichage sur Android TV (plugin Jeedom TV) :
  l'essentiel de la météo du moment, lu dans le cache seulement — jamais
  d'appel à l'IRM —, avec une bande de six colonnes aux icônes Material Design.
- *Température min du jour* : quand l'IRM retire son minimum en cours de
  matinée, la commande ne remonte plus avec les heures restantes (15 °C à 10 h
  le 13/10/2026, après 13 °C relevés à l'aube). Elle tient compte de la
  température observée le matin et garde le plus bas déjà retenu dans la
  journée.

## 0.2 — 29/09/2026

- Les listes d'actions (vigilance et bulletin du matin) affichent le nom
  complet des commandes (`[Objet][Équipement][Commande]`) au lieu de leur
  numéro (`#6737`) : jeedom.cmd.byId ne fournit pas ce nom.

## 0.1

Première version.

- Une commune belge par équipement, choisie dans la liste des 565 communes
  livrée avec le plugin : la recherche fonctionne hors ligne, en français comme
  en néerlandais.
- Observations, prévisions à sept jours, prévisions horaires H+1 à H+3.
- Bulletin rédigé de l'IRM pour aujourd'hui et demain.
- Prévision de pluie à courte échéance, chiffrée, avec la phrase de l'IRM.
- Avertissements officiels jaune, orange et rouge, avec distinction entre ce qui
  est en cours et ce qui est annoncé.
- Tuile unique regroupant l'essentiel, déclinée à l'identique sur mobile, avec
  une bande heure par heure : température et risque de pluie pour chaque heure
  restante de la journée, en barres lisibles d'un coup d'œil.
- Notification automatique en cas de vigilance : seuil configurable, autant
  d'actions Jeedom que voulu, message personnalisable, et une règle
  anti-répétition qui n'annonce une vigilance qu'une fois — à nouveau seulement
  si elle s'aggrave ou si un nouveau phénomène apparaît.
- Bulletin du matin : chaque jour à l'heure choisie, minimum, maximum et
  conseils du jour (parapluie, gel, chaleur, vent, neige, brouillard) envoyés
  aux actions de son choix, une seule fois par jour, avec rattrapage borné à
  deux heures, jours de la semaine, condition facultative et bouton d'essai.
  Désactivé par défaut.
- Commandes du jour même : température min et max, conditions, code
  conditions et risque de pluie du jour.
- Commande *Icône* : nom d'icône Material Design Icons des conditions
  actuelles, pour un affichage hors de Jeedom.
- Les valeurs sont publiées avec leur vraie date de relevé : une donnée périmée
  se voit, et Jeedom marque l'équipement en défaut au bout de 45 minutes.
