# Météo Belgique IRM

Météo belge complète dans Jeedom, à partir des données de l'Institut Royal
Météorologique : observations, prévisions à sept jours, prévisions horaires,
prévision de pluie à courte échéance et avertissements officiels jaune, orange
et rouge.

> Ce plugin n'est pas affilié à l'IRM et n'est ni parrainé ni approuvé par lui.
> Il lit l'interface que l'application mobile officielle utilise pour elle-même.
> Cette interface n'est pas documentée et peut changer sans préavis : c'est
> arrivé trois fois en deux ans. Le plugin est écrit pour continuer d'afficher ce
> qu'il sait plutôt que de s'arrêter, mais une rupture reste possible.

## Configuration

Aucune clé, aucun compte, aucune dépendance à installer.

1. **Ajouter une commune**, et lui donner un nom — « Maison », « Bureau ».
2. Dans l'onglet *Équipement*, taper les premières lettres de la commune et
   cliquer sur la loupe. Les 565 communes belges sont livrées avec le plugin :
   la recherche fonctionne sans réseau, et accepte le français comme le
   néerlandais — « Elsene » trouve Ixelles, « Brugge » trouve Bruges.
3. **Choisir la commune dans la liste.** Le code INS est affiché à côté du nom :
   il lève l'ambiguïté entre communes aux noms voisins, par exemple
   Sint-Niklaas (46021) et Saint-Nicolas (62093).
4. **Enregistrer.** Les commandes sont créées et la météo est relevée aussitôt.

Le réglage global du plugin ne contient que le délai d'attente des requêtes et
la langue des textes de l'IRM. Les valeurs par défaut conviennent.

## Rythme de mise à jour

Le plugin relit la météo **toutes les dix minutes**, ce qui est le pas auquel
l'IRM publie ses observations et sa séquence radar : interroger plus souvent ne
rapporterait rien de neuf.

Après un échec, l'attente double à chaque tentative, de dix minutes à une heure.
Une commune momentanément injoignable est retrouvée au passage suivant ; une
commune devenue invalide cesse de consommer des requêtes pour rien.

## Les commandes

### L'instant présent

| Commande | Ce qu'elle contient |
|---|---|
| `temperature` | température observée, en °C |
| `condition`, `condition_id` | temps en clair, et son code au format WeatherAPI pour les widgets tiers |
| `pressure`, `wind_speed`, `wind_gust`, `wind_direction` | pression en hPa, vent et rafales en km/h, direction en degrés |
| `uv` | indice UV, quand l'IRM le publie |
| `sunrise`, `sunset` | lever et coucher, en entier `HMM` — 732 pour 7 h 32, la convention de Jeedom |
| `data_age` | âge du dernier relevé, en minutes |

### La pluie à courte échéance

C'est le bloc le plus utile en domotique : c'est lui qui rentre le linge et
ferme les vélux.

| Commande | Ce qu'elle contient |
|---|---|
| `rain_now` | intensité actuelle, en mm/h |
| `rain_next` | minutes avant la prochaine pluie, `-1` si rien n'est annoncé |
| `rain_soon` | 1 s'il va pleuvoir dans les trois heures |
| `rain_hint` | la phrase de l'IRM : « Pas de pluie prévue prochainement », « Pluie pendant 2h30 » |

### Les avertissements

| Commande | Ce qu'elle contient |
|---|---|
| `warning_active` | 1 si un avertissement est **en cours** |
| `warning_level` | `-1` inconnu, `0` aucun, `1` jaune, `2` orange, `3` rouge |
| `warning_slug` | type du plus grave, en identifiant stable : `wind`, `rain`, `ice_or_snow`, `thunder`, `fog`, `cold`, `heat`… |
| `warning_slugs` | tous les types en cours, séparés par des virgules et **triés** |
| `warning_label`, `warning_text` | libellé lisible et texte de l'IRM |
| `warning_end` | fin annoncée, en date absolue |
| `next_warning_level`, `next_warning_slug`, `next_warning_start` | le prochain avertissement **à venir** |

> **Annoncé n'est pas en cours.** L'IRM publie ses avertissements à l'avance,
> parfois douze heures avant. Un scénario qui se déclencherait sur la simple
> présence d'un avertissement fermerait les volets une demi-journée trop tôt.
> C'est pourquoi `warning_active` et `warning_level` ne parlent que de ce qui est
> **en cours**, et que le prochain avertissement a ses propres commandes.

### Les prévisions

`temperature_1_min` à `temperature_7_min`, et de même `_max` — attention, **le
numéro est au milieu** : `temperature_3_max`, pas `temperature_max_3`. Plus
`condition_1` à `condition_7`, `condition_id_1` à `4`, et `rain_chance_1` à `3`.

Les prévisions horaires suivent : `temperature_h1` à `h3`, `condition_h1` à `h3`,
`rain_chance_h1` à `h3`.

Enfin `bulletin_0` et `bulletin_1` portent le **bulletin rédigé de l'IRM** pour
aujourd'hui et demain, en français.

### Ce que le plugin ne fournit pas

**L'humidité.** Elle n'existe nulle part dans les données de l'IRM. Plutôt que de
publier une commande qui afficherait 0 %, elle n'est pas créée du tout.

Les pollens ne sont pas repris non plus : l'IRM ne les publie qu'en image, et ce
format a changé deux fois en dix-huit mois.

## Écrire un scénario

Fermer les volets sur avertissement orange ou rouge :

```
Si [Maison][Niveau d'avertissement] >= 2
```

Le test `>= 2` écarte naturellement le `-1` qui signifie « on ne sait pas » :
une panne de réseau ne déclenchera donc rien.

Être prévenu seulement pour le vent :

```
Si [Maison][Types d'avertissement] contient "wind"
```

Les identifiants ne sont pas traduits et ne changent pas avec la langue : un
scénario écrit sur `wind` continue de fonctionner.

Rentrer le linge avant la pluie :

```
Si [Maison][Pluie prochainement] == 1
```

> **Pas de risque de rebouclage.** Jeedom ne déclenche un scénario que lorsque la
> valeur d'une commande change. Un avertissement orange qui dure six heures est
> écrit une fois et ne réveille plus rien ensuite. C'est pourquoi aucune commande
> ne contient de durée relative : un « fin dans 3 h » changerait à chaque passage
> du cron et redéclencherait tout, toutes les dix minutes.

## Être prévenu automatiquement

Le plugin peut exécuter lui-même des actions dès qu'une vigilance touche votre
commune, sans écrire le moindre scénario. Cela se règle dans l'onglet
*Équipement*, section **Être prévenu en cas de vigilance**.

**À partir de quel niveau.** Jamais, jaune, **orange** (défaut) ou rouge. Le
jaune belge se déclenche pour du brouillard ou soixante kilomètres-heure de vent,
plusieurs fois par mois : une notification qui sonne trop souvent finit par être
coupée, et ne sert plus le jour où elle compte.

**Les actions.** Autant que vous voulez, choisies parmi toutes les commandes
d'action de Jeedom : une notification sur votre téléphone, une synthèse vocale,
un scénario, une lampe qui passe au rouge. Le bouton **Tester** envoie un message
d'essai immédiatement — c'est le seul moyen de vérifier votre configuration sans
attendre la prochaine tempête, et c'est là qu'on s'aperçoit qu'on avait oublié de
sélectionner une commande.

**Le message** est modifiable, avec des balises : `#commune#`, `#niveau#`,
`#type#`, `#texte#`, `#debut#`, `#fin#`. Par défaut :

> Vigilance orange — Orage à Soignies, jusqu'au 15/09 à 22:00
> Également en cours : Vent (jaune)

Les autres vigilances en cours sont ajoutées automatiquement, **y compris celles
qui restent sous votre seuil**. Un orage orange accompagné d'un vent jaune, ce
n'est pas la même soirée qu'un orage seul : il faut savoir qu'il y a aussi tout à
rentrer dans le jardin.

### La règle qui évite d'être harcelé

L'IRM renvoie la même vigilance à chaque appel pendant toute sa durée. Six heures
d'orange, relues toutes les dix minutes, cela ferait trente-six messages.

Le plugin mémorise donc, **par type de phénomène**, le niveau déjà annoncé :

| Ce qui arrive | Ce qui se passe |
|---|---|
| Un phénomène nouveau | Vous êtes prévenu |
| Le niveau monte (jaune → orange) | Vous êtes prévenu |
| Le même niveau continue | Silence |
| L'IRM prolonge l'échéance | Silence — c'est fréquent, et le danger n'a pas changé |
| Le niveau redescend | Silence — une bonne nouvelle annoncée comme un incident reste un incident |
| La vigilance se termine | Silence, sauf si vous cochez l'option |
| Le même phénomène revient plus tard | Vous êtes prévenu, c'est un nouvel épisode |

Les horodatages sont délibérément ignorés dans cette comparaison : ce sont eux
qui bougent, pas le danger.

**Deux options**, décochées par défaut : prévenir *à la fin* de la vigilance, et
prévenir *pour les vigilances annoncées à l'avance*. L'IRM publie jusqu'à douze
heures avant : la seconde laisse le temps de rentrer les meubles de jardin, au
prix d'un message de plus par épisode.

Ce mécanisme est indépendant des commandes : `warning_level` continue de
fonctionner pour vos scénarios, et les deux peuvent coexister.

## Quand l'IRM est indisponible

Le plugin **n'efface jamais ce qu'il sait**. Les dernières valeurs connues
restent affichées, mais datées de leur vraie heure de relevé :

- la tuile affiche « données de 11:40 » en rouge au-delà d'une heure ;
- `data_age` grimpe, et passe en alerte à 30 puis 60 minutes ;
- au bout de 45 minutes sans relevé, Jeedom marque lui-même l'équipement en
  *timeout*, le signale sur le dashboard et sur la page Santé, et poste un
  message ;
- un message apparaît dans le centre de messages, et disparaît au premier
  relevé réussi.

C'est délibéré : une donnée périmée qui se présente comme fraîche est plus
dangereuse qu'une erreur franche.

## La tuile

Une seule commande est visible par défaut, la tuile *Météo*, qui rassemble tout :
bandeau d'avertissement s'il y en a un, température et temps, pluie à courte
échéance, minimum et maximum du jour, les trois prochains jours, et l'âge des
données.

Entre les températures du jour et les trois jours à venir, une **bande heure par
heure** répond à la question la plus courante devant un dashboard : va-t-il
pleuvoir cet après-midi ? Chaque colonne porte l'heure, le temps, la température
et une barre dont la hauteur est le risque de pluie — la silhouette de la bande
se lit d'un coup d'œil, là où une rangée de pourcentages ne se lit pas.

La barre de pluie ne s'affiche que lorsqu'il y a de la pluie à annoncer : quand
aucune heure ne dépasse 5 % de risque, la rangée disparaît entièrement plutôt que
d'aligner des rectangles gris vides. Au-delà d'une chance sur trois, le
pourcentage s'écrit sous la barre — c'est le moment où l'on décide de sortir ou
non, et un chiffre s'y lit mieux qu'une hauteur estimée à l'œil.

Elle compte huit colonnes et couvre jusqu'à seize heures : au-delà de huit
colonnes dans la largeur d'une tuile, l'icône tombe sous les dix pixels et
devient illisible. Quand l'amplitude dépasse le nombre de colonnes, les heures
sont regroupées deux par deux — mais **chaque colonne porte alors le risque de
pluie du pire moment de son intervalle**, jamais celui de sa seule première
heure : une averse à 15 h ne doit pas disparaître parce que la colonne s'appelle
« 14 h ».

Elle couvre les heures restantes de la journée. En soirée, quand il en reste
moins de six, elle déborde sur la nuit et le lendemain matin plutôt que de se
réduire à deux colonnes inutiles ; un trait vertical marque alors le passage à
minuit.

Quatre options se règlent dans la configuration du widget : `days`, `rain`,
`range` et `hours` à `0` masquent respectivement la bande des trois jours, la
ligne de pluie, les températures extrêmes et la bande heure par heure.

Les autres commandes existent mais sont masquées : elles servent aux scénarios et
aux graphiques. Elles s'affichent une par une depuis l'onglet *Commandes*.

## Historisation

Cinq commandes sont historisées par défaut : température, pression, vitesse du
vent, niveau d'avertissement et pluie actuelle. Les prévisions ne le sont pas —
l'historique d'une prévision réécrite toutes les heures ne raconte pas le temps
qu'il a fait, mais les hésitations du modèle.

Ne jamais historiser la tuile ni les bulletins : ce sont des textes longs, et la
colonne d'historique de Jeedom est limitée à 127 caractères.
