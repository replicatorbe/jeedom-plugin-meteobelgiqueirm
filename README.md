# Météo Belgique IRM — plugin Jeedom

Météo belge complète dans Jeedom à partir des données de l'Institut Royal
Météorologique : observations, prévisions à sept jours, prévisions horaires,
prévision de pluie à courte échéance et avertissements officiels jaune, orange
et rouge.

Un équipement représente une commune, choisie parmi les 565 communes belges
livrées avec le plugin. Aucune clé, aucun compte, aucune dépendance.

## Ce qui le distingue

- **Rien à installer et rien à payer** : ni compte Market, ni service cloud, ni
  clé d'API, contrairement au plugin Météo officiel qui passe par le cloud
  Jeedom.
- **Le bulletin rédigé de l'IRM**, en français, pour aujourd'hui et demain.
- **La pluie à courte échéance**, chiffrée et avec la phrase de l'IRM — « Pluie
  pendant 2h30 ». C'est ce qui rentre le linge.
- **Les avertissements belges**, avec la distinction entre ce qui est **en
  cours** et ce qui est seulement **annoncé**.
- **Une donnée périmée se voit.** Les valeurs sont publiées avec leur vraie date
  de relevé, et Jeedom marque l'équipement en défaut au bout de 45 minutes.

## Installation

Le plugin n'est pas publié sur le Market. Copier le dépôt dans
`/var/www/html/plugins/meteobelgiqueirm`, puis l'activer dans Jeedom.

## Documentation

[Français](docs/fr_FR/index.md) · [English](docs/en_US/index.md)

## Tests

```
php tests/run.php          # logique de lecture, hors ligne
php tests/check-classes.php # pièges du coeur, sur une installation Jeedom
```

`run.php` rejoue des réponses réelles de l'IRM, conservées dans
`tests/fixtures/`, sans installation Jeedom ni accès réseau. Chaque rupture de
format constatée doit y laisser un fichier : c'est ce qui permet de continuer à
lire l'ancien schéma en même temps que le nouveau.

`check-classes.php` vérifie par réflexion, contre le coeur installé, les trois
pièges qui ne se voient ni à la relecture, ni avec `php -l`, ni hors ligne : une
propriété sans souligné initial, une méthode portant le nom d'une clé du
formulaire, et une méthode héritée dont on réduirait la visibilité. Le dernier a
coûté une panne totale de l'interface : `getCache()` est publique dans `eqLogic`,
la redéclarer en privé rend la classe impossible à charger, et le coeur charge la
classe de chaque plugin actif sur chaque page.

## Source des données et limites

Les données proviennent de l'IRM, via l'interface que son application mobile
utilise pour elle-même. Cette interface n'est pas publique et n'est pas
documentée : elle peut changer sans préavis, et l'a fait trois fois en deux ans.

**Ce plugin n'est pas affilié à l'Institut Royal Météorologique de Belgique et
n'est ni parrainé ni approuvé par lui.**

L'humidité n'est pas fournie : elle n'existe pas dans ces données.

## Licence

AGPL v3 — voir [LICENSE](LICENSE).
