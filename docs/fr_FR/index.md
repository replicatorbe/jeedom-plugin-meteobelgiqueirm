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
| `icon_mdi` | *Icône* : nom d'icône Material Design Icons des conditions actuelles, pour un affichage hors de Jeedom — voir plus bas |

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

### Le jour même

Les prévisions `_1` à `_7` commencent à **demain**. Le jour même a ses propres
commandes — ce sont les valeurs du bulletin du matin, publiées pour que les
scénarios puissent s'en servir aussi :

| Commande | Nom | Ce qu'elle contient |
|---|---|---|
| `temperature_0_min` | Température min du jour | minimum prévu aujourd'hui, en °C |
| `temperature_0_max` | Température max du jour | maximum prévu aujourd'hui, en °C |
| `condition_0` | Conditions du jour | temps de la journée en clair |
| `condition_id_0` | Code conditions du jour | le même, en code WeatherAPI |
| `rain_chance_0` | Risque de pluie du jour | en % |

Elles sont lues dans le bloc *journée* de la prévision de l'IRM, jamais dans
celui de la nuit qui suit. L'IRM vide ce bloc au fil de la journée, et le plugin
se replie alors sur les prévisions heure par heure qui restent :

- **le minimum** disparaît vers la mi-journée — relevé réel à 12 h 44 : absent.
  Tant qu'il reste des heures avant midi, le plus bas d'entre elles le remplace
  (à 6 h 30, c'est bien le minimum du matin, celui qui décide du gel). Ensuite il
  est **inconnu** : la commande garde sa dernière valeur, et la balise `#min#` du
  bulletin reste vide ;
- **le maximum**, quand le bloc a disparu (le soir), devient le plus haut entre
  la température observée et les heures restantes ;
- **le risque de pluie**, de même, le plus haut des heures restantes ;
- **les conditions** ne se replient pas : le soir, la seule candidate serait la
  nuit, et « Ciel dégagé » n'est pas le temps qu'il a fait.

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

## Le bulletin du matin

Chaque jour à l'heure choisie, un message court, par exemple :

> **Météo du jour** — 12°C - 21°C | Prenez un parapluie | Prévoyez une veste chaude

Il remplace l'automatisation Home Assistant du même nom, dont il reprend l'heure
(6 h 30), le format et les conseils. Il se règle dans l'onglet *Équipement*,
section **Le bulletin du matin**, sur le même modèle que les vigilances, et il
est **désactivé par défaut**.

| Réglage | Clé de configuration | Par défaut |
|---|---|---|
| Activer | `bulletin_enable` (`0` / `1`) | `0` |
| Heure d'envoi | `bulletin_time` (`HH:MM`) | `06:30` |
| Jours | `bulletin_days` : chiffres ISO, `1` = lundi ; vide = tous les jours, `0` = aucun | vide |
| Seulement si | `bulletin_condition` : expression Jeedom, vide = toujours | vide |
| Actions | `bulletin_cmds` : identifiants de commandes séparés par des virgules, comme `alert_cmds` | vide |
| En parallèle | `bulletin_background` (`0` / `1`) | `0` |
| Titre | `bulletin_title` | `Météo du jour` |
| Message | `bulletin_message` | `#min#°C - #max#°C \| #conseils#` |

**Les balises**, dans le titre comme dans le message :

| Balise | Contenu |
|---|---|
| `#min#`, `#max#` | minimum et maximum du jour, arrondis au degré — vides s'ils sont inconnus |
| `#conditions#` | temps de la journée en clair |
| `#conseils#` | les conseils du jour, séparés par « \| » |
| `#bulletin#` | le bulletin rédigé de l'IRM pour aujourd'hui (`bulletin_0`) |
| `#commune#` | nom de la commune |
| `#vent#` | vent moyen le plus fort de la journée, en km/h |
| `#pluie#` | risque de pluie du jour, en % (le nombre seul : écrivez `#pluie# %`) |

### Les conseils

Repris de Home Assistant, dans cet ordre, joints par « | » :

| Si… | Conseil |
|---|---|
| pluie, averses ou orage dans la journée | Prenez un parapluie |
| minimum < 0 °C | Couvrez-vous bien, risque de gel |
| sinon minimum < 5 °C | Prévoyez une veste chaude |
| maximum > 25 °C | Beau et chaud, pensez à vous hydrater |
| vent moyen > 40 km/h | Vent fort, sécurisez la terrasse |
| neige dans la journée | Neige prévue, prudence sur la route |
| brouillard ou brume dans la journée | Brouillard, roulez prudemment |
| aucun des cas précédents | Journée agréable en perspective |

Les seuils sont stricts : 25 °C pile n'est pas « chaud ». Les conditions sont lues
sur les **codes** de l'IRM, jamais sur les libellés : pluie = codes 2, 4 à 10,
13, 16 à 21 ; neige = 8 à 13, 20, 22, 23 ; brouillard = 24 à 27. Un même code
peut compter deux fois — les averses de pluie et neige mêlées appellent le
parapluie *et* la prudence sur la route. « Dans la journée », c'est le temps du
bloc journée de l'IRM (matin et après-midi) plus chaque heure restante jusqu'à
22 h : l'averse de 16 h compte, le brouillard de minuit non.

Un **minimum inconnu** n'est pas un minimum doux : ni le gel ni la veste ne sont
alors évalués.

### Une seule fois par jour

Le plugin passe toutes les dix minutes : l'heure est donc arrondie au passage
suivant (6 h 35 part à 6 h 40). Une fois le bulletin parti, la date est
mémorisée dans le cache de l'équipement — le même que celui des vigilances, que
Jeedom sauvegarde à l'arrêt et restaure au démarrage — et il ne repart plus avant
le lendemain.

**Si Jeedom était arrêté à l'heure dite**, le bulletin part encore dans les
**deux heures** qui suivent, jamais au-delà : « Prévoyez une veste chaude » à
midi ne sert plus à rien et ferait croire à une panne. C'est la même fenêtre que
les rappels du plugin de collecte des déchets.

**Enregistrer l'équipement pendant cette fenêtre** ne fait pas partir le bulletin
du jour : activé à 7 h 10, il partira demain matin. Le bouton *Tester* est là
pour voir le résultat tout de suite.

**Sans prévision du jour en mémoire**, le bulletin n'est pas envoyé — « °C - °C |
Journée agréable en perspective » serait faux sans que rien ne le signale. Il
est retenté à chaque passage tant que la fenêtre de deux heures n'est pas close,
et le journal le signale une fois.

### La condition

Facultative, au format des scénarios, et vérifiée à l'heure d'envoi. Pour ne
recevoir le bulletin que si quelqu'un est à la maison :

```
#[Maison][Présence][Quelqu'un]# == 1
```

Le bouton à droite du champ insère une commande info sous sa forme lisible ;
Jeedom l'enregistre sous forme d'identifiant (`#5433# == 1`), si bien qu'un
renommage ne la casse pas.

- **Vraie** : le bulletin part.
- **Fausse** : il est sauté *pour la journée*. Il n'est pas retenté si la
  condition devient vraie plus tard dans la fenêtre : quelqu'un qui rentre à
  8 h 20 n'a que faire du bulletin de 6 h 30.
- **Impossible à calculer** — commande supprimée, faute de frappe, valeur vide :
  il n'est **pas** envoyé non plus, et le journal le signale en *warning*. La
  condition existe pour taire le bulletin quand la maison est vide ; une
  commande de présence cassée ne doit pas se mettre à réveiller un téléphone en
  vacances.

Seuls un booléen et un nombre font foi : `1` et `vrai` passent, un texte que
Jeedom n'a pas su calculer non.

### Tester, et l'option « en parallèle »

**Tester** envoie tout de suite le vrai bulletin du jour, avec la configuration
*enregistrée*, le titre préfixé par « Essai — ». La condition n'est pas
appliquée, mais sa valeur du moment est affichée : c'est le seul moyen de la
vérifier sans attendre le lendemain. L'essai ne touche pas à la mémoire d'envoi.

**En parallèle** confie chaque action à Jeedom, qui la lance dans un processus à
part — l'option du même nom des scénarios. Une synthèse vocale de vingt secondes
ne retarde plus le relevé des autres communes ; en contrepartie, le plugin sait
que l'action est partie, pas qu'elle a réussi. L'essai, lui, s'exécute toujours
au premier plan.

## L'icône, pour un affichage hors de Jeedom

La commande `icon_mdi` (*Icône*) publie un nom d'icône **Material Design Icons**
correspondant aux conditions **actuelles** — `mdi:weather-sunny`,
`mdi:weather-night`… — pour un écran qui ne sait dessiner qu'un nom d'icône, une
télévision par exemple.

| Codes IRM | Conditions | Icône de jour | Icône de nuit |
|---|---|---|---|
| 0 | ensoleillé | `mdi:weather-sunny` | `mdi:weather-night` |
| 1, 3 | peu ou partiellement nuageux | `mdi:weather-partly-cloudy` | `mdi:weather-night-partly-cloudy` |
| 14, 15 | nuageux, couvert | `mdi:weather-cloudy` | idem |
| 4, 6, 18 | averses, pluie | `mdi:weather-rainy` | idem |
| 16, 19 | pluie forte | `mdi:weather-pouring` | idem |
| 2, 5, 7, 10, 17 | averses orageuses, pluie orageuse | `mdi:weather-lightning-rainy` | idem |
| 13 | averses de neige orageuses | `mdi:weather-lightning` | idem |
| 8, 9, 20 | pluie et neige mêlées | `mdi:weather-snowy-rainy` | idem |
| 11, 12, 22, 23 | neige | `mdi:weather-snowy` | idem |
| 21 | pluie verglaçante | `mdi:weather-hail` | idem |
| 24 à 27 | brume, brouillard | `mdi:weather-fog` | idem |
| inconnu | — | `mdi:weather-cloudy` | idem |

Trois choix à connaître :

- **Code inconnu → ciel couvert.** C'est l'icône qui promet le moins, et un point
  d'interrogation sur une télévision se lit comme une panne de l'écran.
- **La pluie verglaçante prend la grêle**, seul pictogramme qui dise « glace » :
  pluie et neige mêlées annoncerait des flocons qui ne tombent pas.
- **Le vent.** L'IRM n'a pas de code « vent » : par temps sec (codes 0, 1, 3, 14,
  15) et au-delà de 50 km/h de vent moyen, l'icône devient `mdi:weather-windy`
  (`mdi:weather-windy-variant` sous les nuages). Par temps de pluie, la pluie
  reste l'information utile.

Le jeu d'icônes est celui de Home Assistant : les variantes plus récentes de la
police (« partly-rainy »…) manquent aux versions anciennes et s'afficheraient
comme un carré vide. Quand l'observation ne donne pas de code, la commande garde
sa valeur précédente.

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
