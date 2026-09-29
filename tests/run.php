<?php
/* Rejeu hors ligne du plugin sur des réponses réelles de l'IRM.
 *
 *   php tests/run.php
 *
 * Les fixtures de tests/fixtures/ sont des réponses capturées : chaque panne de
 * production doit y laisser un fichier, qui devient un test de non-régression
 * permanent. */

require_once __DIR__ . '/stub.php';

/* La classe charge le coeur de Jeedom en première ligne ; hors installation, on
 * la recopie sans ce require et on l'inclut. La copie est déposée À CÔTÉ de
 * l'originale et non dans tests/ : la classe résout communes.json en relatif de
 * son propre dossier, et un __DIR__ déplacé la ferait chercher au mauvais
 * endroit. Elle est effacée en sortant, même en cas d'erreur fatale. */
$original = __DIR__ . '/../core/class/meteobelgiqueirm.class.php';
$copy = __DIR__ . '/../core/class/.meteobelgiqueirm.test.php';
$source = preg_replace('#^\s*require_once .*core\.inc\.php.*$#m', '', file_get_contents($original));
file_put_contents($copy, $source);
register_shutdown_function(function () use ($copy) {
    if (file_exists($copy)) { unlink($copy); }
});
require_once $copy;

$passed = 0;
$failed = 0;

function check($_label, $_actual, $_expected) {
    global $passed, $failed;
    $ok = ($_actual === $_expected);
    if ($ok) {
        $passed++;
        printf("  ok    %-52s %s\n", $_label, var_export($_actual, true));
    } else {
        $failed++;
        printf("  ECHEC %-52s obtenu %s, attendu %s\n", $_label,
            var_export($_actual, true), var_export($_expected, true));
    }
}

function section($_title) {
    echo "\n" . $_title . "\n" . str_repeat('-', strlen($_title)) . "\n";
}

/* Donne accès aux méthodes privées : on teste la logique, pas la visibilité. */
function invoke($_object, $_method, $_args = array()) {
    $m = new ReflectionMethod('meteobelgiqueirm', $_method);
    $m->setAccessible(true);
    return $m->invokeArgs($_object, $_args);
}

$eq = new meteobelgiqueirm();

/* ================================================== CONVERSIONS NUMÉRIQUES */
section('Conversions numériques');

/* Le piège central du portage : (float) null vaut 0.0 en PHP sans avertir.
 * Un champ que l'IRM cesserait d'envoyer deviendrait 0 °C silencieusement. */
check('fnum(null)', meteobelgiqueirm::fnum(null), null);
check('fnum("")', meteobelgiqueirm::fnum(''), null);
check('fnum("12")', meteobelgiqueirm::fnum('12'), 12.0);
check('fnum(0)', meteobelgiqueirm::fnum(0), 0.0);
check('fnum("abc")', meteobelgiqueirm::fnum('abc'), null);
check('fnum(array())', meteobelgiqueirm::fnum(array()), null);
check('fint("7")', meteobelgiqueirm::fint('7'), 7);
check('fint(null)', meteobelgiqueirm::fint(null), null);

/* ================================================= DIRECTION DU VENT */
section('Direction du vent');

/* L'API rend l'angle de rotation d'une flèche pointant le nord, pas la
 * direction d'origine du vent : sans correction, tout est à 180°. */
check('0 degre devient sud', meteobelgiqueirm::windDirection(0), 180);
check('90 degres devient ouest', meteobelgiqueirm::windDirection(90), 270);
check('270 degres devient est', meteobelgiqueirm::windDirection(270), 90);
check('variable rend null', meteobelgiqueirm::windDirection(90, array('en' => 'VAR')), null);
check('absente rend null', meteobelgiqueirm::windDirection(null), null);

/* ========================================================= LEVER / COUCHER */
section('Lever et coucher en entier HMM');

check('7h32', meteobelgiqueirm::secondsToHmm(27120), 732);
check('minuit', meteobelgiqueirm::secondsToHmm(0), 0);
check('20h05', meteobelgiqueirm::secondsToHmm(72300), 2005);

/* ================================================================ TEXTES */
section('Assainissement et repli de langue');

check('chevrons retires',
    meteobelgiqueirm::sanitizeText('a</script>b'), 'a/scriptb');
check('espaces normalises',
    meteobelgiqueirm::sanitizeText("  deux   espaces \n fin "), 'deux espaces fin');
/* Le bulletin n'existe qu'en français et en néerlandais pour la Belgique. */
check('repli fr quand en absent',
    meteobelgiqueirm::pickLang(array('fr' => 'Soleil', 'nl' => 'Zon'), 'en'), 'Soleil');
check('preference respectee',
    meteobelgiqueirm::pickLang(array('fr' => 'Soleil', 'nl' => 'Zon'), 'nl'), 'Zon');
check('champ absent rend vide', meteobelgiqueirm::pickLang(null), '');

/* ============================================================= CONDITIONS */
section('Table des conditions');

$d = meteobelgiqueirm::describeWw(0, 'd');
check('ww 0 de jour', $d[0], 'Ensoleillé');
check('ww 0 de jour, code WeatherAPI', $d[1], 1000);
$n = meteobelgiqueirm::describeWw(0, 'n');
check('ww 0 de nuit', $n[0], 'Ciel dégagé');
/* Au-delà de 1, l'API ne distingue plus jour et nuit. */
check('ww 18 identique de nuit',
    meteobelgiqueirm::describeWw(18, 'n')[0], meteobelgiqueirm::describeWw(18, 'd')[0]);
check('ww 25', meteobelgiqueirm::describeWw(25)[0], 'Brouillard');
check('ww inconnu rend vide', meteobelgiqueirm::describeWw(99)[0], '');
check('ww null rend vide', meteobelgiqueirm::describeWw(null)[0], '');

/* ========================================================= RECHERCHE COMMUNE */
section('Recherche de commune');

check('normalisation des accents', meteobelgiqueirm::normalize('Liège'), 'liege');
check('normalisation des traits', meteobelgiqueirm::normalize('Ottignies-Louvain-La-Neuve'),
    'ottignieslouvainlaneuve');

$communes = meteobelgiqueirm::communes();
check('les 565 communes sont livrees', count($communes), 565);
check('Bruxelles', isset($communes['21004']) ? $communes['21004']['fr'] : '', 'Bruxelles');

$found = meteobelgiqueirm::searchCommunes('liege');
check('recherche sans accent trouve Liege', isset($found['62063']), true);
/* La liste embarquée sait chercher dans les deux langues. */
$found = meteobelgiqueirm::searchCommunes('Elsene');
check('recherche en neerlandais trouve Ixelles', isset($found['21009']), true);
$found = meteobelgiqueirm::searchCommunes('Brugge');
check('Brugge trouve Bruges', isset($found['31005']), true);
check('recherche vide ne rend rien', count(meteobelgiqueirm::searchCommunes('')), 0);

/* ================================================== BUG DE MINUIT, HORAIRE */
section('Reconstruction des dates horaires');

/* Les échéances horaires ne portent aucune date : seule l'entrée de minuit
 * porte un dateShow. Entre 00 h et 01 h, cette entrée est la PREMIÈRE de la
 * liste — l'incrémenter décalerait toute la journée. */
$raw = array('for' => array('hourly' => array(
    array('hour' => '00', 'dateShow' => '15/09', 'temp' => 12, 'ww' => '0'),
    array('hour' => '01', 'dateShow' => null,    'temp' => 11, 'ww' => '0'),
    array('hour' => '02', 'dateShow' => null,    'temp' => 10, 'ww' => '0'),
)));
$hourly = invoke($eq, 'parseHourly', array($raw));
$firstDay = date('Y-m-d', $hourly[0]['ts']);
$lastDay  = date('Y-m-d', $hourly[2]['ts']);
check('minuit en tete ne decale pas le jour', $firstDay, date('Y-m-d'));
check('les heures suivantes restent le meme jour', $lastDay, $firstDay);
check('heure lue correctement', (int) date('H', $hourly[2]['ts']), 2);

/* En journée, l'entrée de minuit est au milieu et doit, elle, faire changer
 * de jour. */
$raw = array('for' => array('hourly' => array(
    array('hour' => '22', 'dateShow' => null,    'temp' => 15, 'ww' => '0'),
    array('hour' => '23', 'dateShow' => null,    'temp' => 14, 'ww' => '0'),
    array('hour' => '00', 'dateShow' => '16/09', 'temp' => 13, 'ww' => '0'),
)));
$hourly = invoke($eq, 'parseHourly', array($raw));
check('minuit au milieu fait changer de jour',
    date('Y-m-d', $hourly[2]['ts']), date('Y-m-d', strtotime('+1 day')));

/* Types instables : ww est une chaîne dans hourly, un entier dans daily. */
check('ww chaine converti en entier', $hourly[0]['ww'], 0);

/* ================================================= INDEXATION PAR DATE */
section('Prévisions journalières indexées par date');

/* Le soir, la première entrée est « Cette nuit » avec tempMax nul : indexer par
 * position viderait la température maximale du jour tous les soirs. */
$raw = array('for' => array('daily' => array(
    array('dayName' => array('en' => 'Tonight', 'fr' => 'Cette nuit'), 'dayNight' => 'n',
          'tempMin' => 16, 'tempMax' => null, 'ww1' => 0, 'precipChance' => 0,
          'text' => array('fr' => 'Nuit claire')),
    array('dayName' => array('en' => 'Tomorrow', 'fr' => 'Demain'), 'dayNight' => 'd',
          'tempMin' => 14, 'tempMax' => 23, 'ww1' => 1, 'precipChance' => 20,
          'text' => array('fr' => 'Belle journee')),
)));
$days = invoke($eq, 'parseDaily', array($raw));
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
check('le bloc nuit est range sur aujourd hui', isset($days[$today]), true);
check('demain est range sur demain', isset($days[$tomorrow]), true);
check('temperature max de demain', $days[$tomorrow]['tmax'], 23.0);
check('le bloc nuit garde son minimum', $days[$today]['tmin'], 16.0);

/* Un minimum supérieur au maximum arrive : on échange plutôt que de publier
 * une absurdité. */
$raw = array('for' => array('daily' => array(
    array('dayName' => array('en' => 'Tomorrow'), 'dayNight' => 'd',
          'tempMin' => 25, 'tempMax' => 12, 'ww1' => 0),
)));
$days = invoke($eq, 'parseDaily', array($raw));
check('min et max inverses sont remis en ordre', $days[$tomorrow]['tmin'], 12.0);
check('max apres echange', $days[$tomorrow]['tmax'], 25.0);

/* L'IRM glisse des annonces de service dans la liste des prévisions. */
$raw = array('for' => array('daily' => array(
    array('dayName' => array('en' => 'End of support', 'fr' => 'Fin de support'),
          'tempMin' => 0, 'tempMax' => null, 'ww1' => 0),
    array('dayName' => array('en' => 'Tomorrow'), 'dayNight' => 'd',
          'tempMin' => 10, 'tempMax' => 20, 'ww1' => 0),
)));
$days = invoke($eq, 'parseDaily', array($raw));
check('annonce de service ecartee', count($days), 1);

/* ============================================================ AVERTISSEMENTS */
section('Avertissements');

$now = time();
$model = array(
    'fetched_at' => $now,
    'warnings' => array(
        /* En cours, jaune */
        array('id' => 7, 'slug' => 'fog', 'name' => 'Brouillard', 'level' => 1,
              'text' => 'Brouillard dense', 'from' => $now - 3600, 'to' => $now + 3600),
        /* En cours, orange : c'est lui le plus grave */
        array('id' => 0, 'slug' => 'wind', 'name' => 'Vent', 'level' => 2,
              'text' => 'Rafales', 'from' => $now - 1800, 'to' => $now + 7200),
        /* À venir : ne doit surtout pas compter comme actif */
        array('id' => 3, 'slug' => 'thunder', 'name' => 'Orage', 'level' => 3,
              'text' => 'Orages', 'from' => $now + 43200, 'to' => $now + 50400),
    ),
);
$w = invoke($eq, 'warningsAt', array($model, $now));
check('un avertissement est actif', $w['active'], 1);
check('le niveau retenu est le plus grave', $w['level'], 2);
check('le type retenu est celui du plus grave', $w['slug'], 'wind');
check('seuls les actifs sont comptes', $w['count'], 2);
/* Tri alphabétique : sans lui, une permutation de l'API réveillerait les
 * scénarios à chaque passage du cron. */
check('les types actifs sont tries', $w['slugs'], 'fog,wind');
check('l orage a venir n est pas actif', strpos($w['slugs'], 'thunder'), false);
check('le prochain avertissement est annonce', $w['next_slug'], 'thunder');
check('niveau du prochain', $w['next_level'], 3);
/* Instant absolu, jamais une durée relative. */
check('la fin est un instant absolu', $w['end'], date('Y-m-d H:i', $now + 7200));

/* Aucun avertissement : zéro, qui est une affirmation. */
$w = invoke($eq, 'warningsAt', array(array('fetched_at' => $now, 'warnings' => array()), $now));
check('aucun avertissement donne zero', $w['level'], 0);
check('aucun avertissement, inactif', $w['active'], 0);

/* Jamais lu : inconnu, qui n'est pas la même chose que zéro. */
$w = invoke($eq, 'warningsAt', array(array(), $now));
check('jamais lu donne inconnu', $w['level'], -1);

/* ================================================================= NOWCAST */
section('Pluie à courte échéance');

$model = array('nowcast' => array(
    'hint' => 'Pluie pendant 30 minutes', 'per_hour' => 6.0,
    'frames' => array(
        array('ts' => $now - 600, 'value' => 0.0),
        array('ts' => $now - 60,  'value' => 0.5),
        array('ts' => $now + 600, 'value' => 0.0),
        array('ts' => $now + 1200, 'value' => 0.9),
    ),
));
$r = invoke($eq, 'nowcastAt', array($model, $now));
/* Les valeurs belges sont en mm par tranche de dix minutes : x6 pour des mm/h. */
check('intensite actuelle convertie en mm/h', $r['now'], 3.0);
check('minutes avant la prochaine pluie', $r['next'], 20);
check('pluie annoncee', $r['soon'], 1);
check('la phrase de l IRM est reprise', $r['hint'], 'Pluie pendant 30 minutes');

$r = invoke($eq, 'nowcastAt', array(array('nowcast' => array('frames' => array())), $now));
check('sans sequence, pas de pluie annoncee', $r['soon'], 0);

/* ======================================================== HORS BELGIQUE */
section('Garde-fou hors Belgique');

/* L'API ne dit pas non : elle répond 200 avec la météo de Bruxelles. */
$raw = array('cityName' => 'Hors de Belgique (Bxl)', 'country' => 'BE', 'obs' => array('temp' => 20));
try {
    invoke($eq, 'buildModel', array($raw));
    check('commune hors Belgique refusee', false, true);
} catch (Throwable $e) {
    check('commune hors Belgique refusee', true, true);
}

$raw = array('cityName' => 'Amsterdam', 'country' => 'NL', 'obs' => array('temp' => 20));
try {
    invoke($eq, 'buildModel', array($raw));
    check('commune etrangere refusee', false, true);
} catch (Throwable $e) {
    check('commune etrangere refusee', true, true);
}

/* ============================================ REJEU DES RÉPONSES CAPTURÉES */
section('Rejeu des réponses réelles');

$fixtures = glob(__DIR__ . '/fixtures/*.json');
if (empty($fixtures)) {
    echo "  (aucune fixture dans tests/fixtures/)\n";
}
foreach ($fixtures as $file) {
    $name = basename($file, '.json');
    $raw = json_decode(file_get_contents($file), true);
    if (!is_array($raw)) {
        check($name . ' : JSON lisible', false, true);
        continue;
    }
    try {
        $model = invoke($eq, 'buildModel', array($raw));
        printf("  ok    %-52s %s, %d jours, %d heures, %d avert.\n",
            $name . ' : modele construit',
            $model['city'], count($model['days']), count($model['hourly']), count($model['warnings']));
        $passed++;

        /* Aucune température ne doit valoir exactement zéro par accident de
         * conversion : c'est la signature du piège (float) null. */
        $suspect = ($model['obs']['temp'] === 0.0 && !isset($raw['obs']['temp']));
        check($name . ' : pas de zero fabrique', $suspect, false);
    } catch (Throwable $e) {
        check($name . ' : modele construit', $e->getMessage(), true);
    }
}

/* ================================================================ ALERTES */
section('Notification des alertes');

/*
 * Les réglages doivent exister avant le premier affichage du formulaire : un
 * menu déroulant sans valeur se présente sur sa première entrée — « Jamais » —
 * et le premier enregistrement couperait les notifications sans que personne ne
 * l'ait demandé.
 */
$neuf = new meteobelgiqueirm();
$neuf->preSave();
check('le seuil par défaut est l\'orange', (int) $neuf->getConfiguration('alert_threshold'), 2);
check('un gabarit de message est fourni',
    strpos($neuf->getConfiguration('alert_message'), '#niveau#') !== false, true);
check('la notification de fin est muette par défaut',
    (int) $neuf->getConfiguration('alert_on_end'), 0);

/* Un équipement dédié, configuré comme le ferait l'utilisateur. */
$alerted = new meteobelgiqueirm();
$alerted->configuration = array(
    'ins' => '55040',
    'alert_threshold' => 2,      // à partir de l'orange
    'alert_cmds' => '101,102',   // deux actions
);

function alertCycle($_eq, $_warnings, $_now) {
    cmd::$sent = array();
    $m = new ReflectionMethod('meteobelgiqueirm', 'checkAlerts');
    $m->setAccessible(true);
    $m->invoke($_eq, array('warnings' => $_warnings, 'city' => 'Soignies'), $_now);
    return cmd::$sent;
}

$t = time();
$orage = array('id' => 3, 'slug' => 'thunder', 'name' => 'Orage', 'level' => 2,
               'text' => 'Orages violents', 'from' => $t - 600, 'to' => $t + 21600);
$vent  = array('id' => 0, 'slug' => 'wind', 'name' => 'Vent', 'level' => 1,
               'text' => 'Rafales', 'from' => $t - 600, 'to' => $t + 10800);

/* Premier passage : l'orange déclenche, sur les deux actions configurées. */
$sent = alertCycle($alerted, array($orage), $t);
check('une alerte orange déclenche', count($sent), 2);
check('le message nomme le phénomène',
    strpos($sent[0]['message'], 'Orage') !== false, true);
check('le message nomme la commune',
    strpos($sent[0]['message'], 'Soignies') !== false, true);
check('le titre est renseigné', $sent[0]['title'] !== '', true);

/* LE test : la même alerte, dix minutes plus tard, ne doit plus rien envoyer.
 * Sans cela, six heures d'orange produiraient trente-six notifications. */
$sent = alertCycle($alerted, array($orage), $t + 600);
check('la même alerte ne se répète pas', count($sent), 0);

/* L'IRM prolonge très souvent une alerte en cours : la fin change, le danger
 * non. Cela ne doit pas renotifier. */
$prolonge = $orage;
$prolonge['to'] = $t + 43200;
$sent = alertCycle($alerted, array($prolonge), $t + 1200);
check('une échéance prolongée ne renotifie pas', count($sent), 0);

/* Une alerte jaune qui s'ajoute reste sous le seuil : silence. */
$sent = alertCycle($alerted, array($prolonge, $vent), $t + 1800);
check('une alerte sous le seuil ne déclenche pas', count($sent), 0);

/* Mais dès que quelque chose franchit le seuil, le message annonce TOUT ce qui
 * est en cours : un orage orange avec du vent jaune, ce n'est pas la même
 * soirée qu'un orage seul — il faut savoir qu'il y a aussi tout à rentrer. */
$pluie = array('id' => 1, 'slug' => 'rain', 'name' => 'Pluie', 'level' => 2,
               'text' => 'Fortes pluies', 'from' => $t, 'to' => $t + 7200);
$sent = alertCycle($alerted, array($prolonge, $vent, $pluie), $t + 2400);
check('une nouvelle alerte au seuil déclenche', count($sent), 2);
check('le message cite aussi le vent resté sous le seuil',
    strpos($sent[0]['message'], 'Vent') !== false, true);

/* Aggravation : l'orange passe au rouge, il faut reprévenir. */
$rouge = $prolonge;
$rouge['level'] = 3;
$sent = alertCycle($alerted, array($rouge), $t + 3000);
check('une aggravation renotifie', count($sent), 2);
check('le message dit rouge', strpos($sent[0]['message'], 'rouge') !== false, true);

/* Retour au calme relatif : surtout pas de notification. Une bonne nouvelle
 * annoncée comme un incident reste un incident. */
$redescendu = $rouge;
$redescendu['level'] = 2;
$sent = alertCycle($alerted, array($redescendu), $t + 3600);
check('une accalmie ne notifie pas', count($sent), 0);

/* Fin d'alerte : silencieuse par défaut. */
$sent = alertCycle($alerted, array(), $t + 4200);
check('la fin est silencieuse par défaut', count($sent), 0);

/* Un nouvel épisode du même type, plus tard, doit de nouveau prévenir. */
$sent = alertCycle($alerted, array($orage), $t + 4800);
check('un nouvel épisode prévient à nouveau', count($sent), 2);

/* Option « prévenir à la fin ». */
$alerted->configuration['alert_on_end'] = 1;
$sent = alertCycle($alerted, array(), $t + 5400);
check('la fin notifie quand l\'option est active', count($sent), 2);
check('le message de fin le dit',
    strpos($sent[0]['message'], 'Fin de vigilance') !== false, true);

/* Seuil à zéro : le mécanisme est désactivé, même en vigilance rouge. */
$muet = new meteobelgiqueirm();
$muet->configuration = array('ins' => '55040', 'alert_threshold' => 0, 'alert_cmds' => '101');
$sent = alertCycle($muet, array($rouge), $t);
check('le seuil « jamais » désactive tout', count($sent), 0);

/* Aucune action choisie : rien ne part, et le journal le dit. */
$sansAction = new meteobelgiqueirm();
$sansAction->configuration = array('ins' => '55040', 'alert_threshold' => 2, 'alert_cmds' => '');
log::$lines = array();
$sent = alertCycle($sansAction, array($orage), $t);
check('sans action configurée, rien n\'est envoyé', count($sent), 0);
check('et le journal le signale', count(log::$lines) > 0, true);

/* ========================================================== BANDE HORAIRE */
section('Bande heure par heure');

/* Construite depuis une réponse réelle : c'est la seule façon de mesurer la
 * charge que la commande devra porter. */
$raw = json_decode(file_get_contents(__DIR__ . '/fixtures/forecast_25121.json'), true);
$model = invoke($eq, 'buildModel', array($raw));
/* L'API date ses heures par rapport au jour de l'appel : la réponse figée
 * commence donc chaque jour à la même heure, et « maintenant » doit s'y caler.
 * Avec time(), la bande dépendait de l'heure du lancement : avant la première
 * heure de la réponse, sa première colonne était une heure à venir. */
$bandNow = $model['hourly'][0]['ts'] + 50 * 60;
$hours = invoke($eq, 'buildHours', array($model, $bandNow));

check('la bande n\'est pas vide', count($hours) > 0, true);
check('elle ne dépasse pas la largeur tenable', count($hours) <= 8, true);

$fields = true;
$chronological = true;
$previous = null;
foreach ($hours as $h) {
    if (!array_key_exists('h', $h) || !array_key_exists('i', $h)
        || !array_key_exists('t', $h) || !array_key_exists('r', $h)
        || !array_key_exists('n', $h)) { $fields = false; }
    if ($previous !== null && $h['n'] === 0 && $h['h'] <= $previous) { $chronological = false; }
    $previous = $h['h'];
}
check('chaque colonne porte ses cinq champs', $fields, true);
check('les heures se suivent', $chronological, true);
/* Le changement de jour doit être marqué, sinon « 23 » suivi de « 0 » se lit
 * comme une erreur d'affichage. */
$marked = true;
$previous = null;
foreach ($hours as $h) {
    if ($previous !== null && $h['h'] < $previous && $h['n'] !== 1) { $marked = false; }
    $previous = $h['h'];
}
check('le passage à minuit est marqué', $marked, true);

/* La première colonne est l'heure en cours, pas la suivante : à 14 h 50, la
 * colonne « 14 » décrit encore le temps qu'il fait. */
check('la bande commence à l\'heure courante',
    $hours[0]['h'] === (int) date('G', $bandNow), true);

/*
 * Regroupement : quand la bande couvre plus d'heures qu'elle n'a de colonnes,
 * chaque colonne doit porter le risque de pluie du PIRE moment de son
 * intervalle. Une averse à 15 h qui disparaîtrait parce que la colonne
 * s'appelle « 14 h » serait pire que pas de bande du tout.
 */
$base = time() - 600;
$fake = array('hourly' => array());
for ($i = 0; $i < 16; $i++) {
    $fake['hourly'][] = array(
        'ts' => $base + $i * 3600, 'temp' => 15.0, 'ww' => 0, 'day_night' => 'd',
        /* Une seule heure pluvieuse, volontairement en position impaire pour
         * qu'un échantillonnage naïf la manque. */
        'rain_chance' => ($i === 5) ? 90.0 : 0.0,
    );
}
$grouped = invoke($eq, 'buildHours', array($fake, $base));
check('le regroupement tient dans huit colonnes', count($grouped) <= 8, true);
$maxRisk = 0;
foreach ($grouped as $g) { if ($g['r'] > $maxRisk) { $maxRisk = $g['r']; } }
check('l\'averse isolée survit au regroupement', $maxRisk, 90);

/* Le coeur tronque la valeur d'une commande à 3096 caractères. Une tuile
 * tronquée, c'est un JSON invalide et un widget muet. */
$warning = invoke($eq, 'warningsAt', array($model, time()));
$nowcast = invoke($eq, 'nowcastAt', array($model, time()));
$ww = meteobelgiqueirm::describeWw($model['obs']['ww'], $model['obs']['day_night']);
$resume = invoke($eq, 'buildResume', array($model, $warning, $nowcast, $ww, time()));

printf("  ok    %-52s %d caractères\n", 'taille de la charge de la tuile', strlen($resume));
$passed++;
check('la tuile tient sous la limite du coeur', strlen($resume) < 3096, true);
check('la tuile est un JSON valide', json_decode($resume, true) !== null, true);

/* ==================================================== NOMS DES COMMANDES */
section('Noms des commandes');

/*
 * cmd::setName() passe par cleanComponanteName(), qui retire les apostrophes
 * sans le dire : « Niveau d'avertissement » arrive en base sous la forme
 * « Niveau davertissement ». Le nom se lit alors comme une faute de frappe, et
 * rien dans le code ne le laisse deviner.
 */
$classSource = file_get_contents(__DIR__ . '/../core/class/meteobelgiqueirm.class.php');
preg_match_all("/addCmdIfMissing\(\s*'[a-z_0-9]+'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'/", $classSource, $names);

$withApostrophe = array();
foreach ($names[1] as $name) {
    if (strpos($name, "\\'") !== false) {
        $withApostrophe[] = $name;
    }
}
check('aucun nom de commande ne porte d\'apostrophe', count($withApostrophe), 0);
if (!empty($withApostrophe)) {
    foreach ($withApostrophe as $name) { echo '        -> ' . $name . "\n"; }
}
check('des commandes sont bien déclarées', count($names[1]) > 20, true);

/* ============================================================== GABARITS */
section('Gabarits de widget');

$dashboard = __DIR__ . '/../core/template/dashboard/cmd.info.string.meteobelgiqueirm.html';
$mobile = __DIR__ . '/../core/template/mobile/cmd.info.string.meteobelgiqueirm.html';

check('le gabarit dashboard existe', is_readable($dashboard), true);
check('le gabarit mobile existe', is_readable($mobile), true);
/* Jeedom cherche le gabarit dans deux dossiers distincts ; un mobile oublié
 * laisse l'application afficher la valeur brute. */
check('dashboard et mobile identiques',
    is_readable($dashboard) && is_readable($mobile)
        && md5_file($dashboard) === md5_file($mobile), true);

$template = is_readable($dashboard) ? file_get_contents($dashboard) : '';

/*
 * Le navigateur ne lit pas les commentaires JavaScript avant de chercher la fin
 * du bloc : il coupe à la PREMIÈRE balise de fermeture rencontrée, même à
 * l'intérieur d'une chaîne ou d'un commentaire. Tout ce qui suit s'affiche alors
 * en texte brut sur le dashboard. C'est arrivé, et sur le commentaire qui
 * expliquait précisément ce danger.
 */
$open = substr_count($template, '<' . 'script>');
$close = substr_count($template, '<' . '/script>');
check('autant d\'ouvertures que de fermetures de script', $open === $close, true);
check('une seule fermeture de script', $close, 1);

/* Sans ces deux marqueurs, jeedom.cmd.update ne retrouve pas la tuile et la
 * valeur n'est jamais rafraîchie sans rechargement de page. */
check('la racine porte les classes attendues',
    strpos($template, 'class="cmd cmd-widget') !== false, true);
check('la racine porte l\'identifiant de commande',
    strpos($template, 'data-cmd_id="#id#"') !== false, true);

/* Le texte venu de l'IRM ne doit jamais être injecté en HTML. */
check('aucun innerHTML sur du texte de l\'IRM',
    preg_match('/innerHTML\s*=\s*[^\x27"]*(?:data|_data)\./', $template), 0);

/* ============================================================== CONSEILS */
section('Conseils du bulletin du matin');

/* Un conseil par cas, dans l'ordre de l'automatisation Home Assistant. */
function advice($_tmin, $_tmax, $_wind, $_codes) {
    return implode(' | ', meteobelgiqueirm::bulletinAdvice($_tmin, $_tmax, $_wind, $_codes));
}
check('rien à signaler', advice(10, 20, 15, array(0, 1)), 'Journée agréable en perspective');
check('pluie (18)', advice(10, 20, 15, array(3, 18)), 'Prenez un parapluie');
check('averses (4)', advice(10, 20, 15, array(4)), 'Prenez un parapluie');
check('orage (17)', advice(10, 20, 15, array(17)), 'Prenez un parapluie');
check('gel sous zéro', advice(-2, 6, 10, array(0)), 'Couvrez-vous bien, risque de gel');
check('zéro pile n\'est pas du gel', advice(0, 6, 10, array(0)), 'Prévoyez une veste chaude');
check('veste sous cinq degrés', advice(4, 12, 10, array(0)), 'Prévoyez une veste chaude');
check('cinq pile, pas de veste', advice(5, 12, 10, array(0)), 'Journée agréable en perspective');
check('chaleur au-delà de 25', advice(15, 26, 10, array(0)), 'Beau et chaud, pensez à vous hydrater');
check('25 pile n\'est pas chaud', advice(15, 25, 10, array(0)), 'Journée agréable en perspective');
check('vent au-delà de 40', advice(10, 20, 41, array(0)), 'Vent fort, sécurisez la terrasse');
check('40 pile n\'est pas fort', advice(10, 20, 40, array(0)), 'Journée agréable en perspective');
check('neige (23)', advice(1, 3, 10, array(23)), 'Prévoyez une veste chaude | Neige prévue, prudence sur la route');
/* Pluie et neige mêlées : les deux conseils à la fois. */
check('pluie et neige mêlées (20)', advice(2, 4, 10, array(20)),
    'Prenez un parapluie | Prévoyez une veste chaude | Neige prévue, prudence sur la route');
check('brouillard (25)', advice(8, 15, 5, array(25)), 'Brouillard, roulez prudemment');
check('brume (26) compte comme brouillard', advice(8, 15, 5, array(26)), 'Brouillard, roulez prudemment');
check('tout à la fois, dans l\'ordre', advice(-1, 26, 50, array(18, 22, 27)),
    'Prenez un parapluie | Couvrez-vous bien, risque de gel | Beau et chaud, pensez à vous hydrater'
    . ' | Vent fort, sécurisez la terrasse | Neige prévue, prudence sur la route | Brouillard, roulez prudemment');
/* Un minimum inconnu n'est pas un minimum doux : ni gel ni veste. */
check('minimum inconnu, pas de conseil de froid', advice(null, 12, 10, array(0)), 'Journée agréable en perspective');
check('codes en chaînes acceptés', advice(10, 20, 15, array('18')), 'Prenez un parapluie');

/* ================================================================ ICÔNES */
section('Icône Material Design');

check('0 de jour', meteobelgiqueirm::mdiIcon(0, 'd'), 'mdi:weather-sunny');
check('0 de nuit', meteobelgiqueirm::mdiIcon(0, 'n'), 'mdi:weather-night');
check('1 de nuit', meteobelgiqueirm::mdiIcon(1, 'n'), 'mdi:weather-night-partly-cloudy');
check('3 de nuit prend la lune', meteobelgiqueirm::mdiIcon(3, 'n'), 'mdi:weather-night-partly-cloudy');
check('15 couvert', meteobelgiqueirm::mdiIcon(15, 'd'), 'mdi:weather-cloudy');
check('16 pluie forte', meteobelgiqueirm::mdiIcon(16, 'd'), 'mdi:weather-pouring');
check('17 pluie orageuse', meteobelgiqueirm::mdiIcon(17, 'd'), 'mdi:weather-lightning-rainy');
check('18 pluie de nuit, même icône', meteobelgiqueirm::mdiIcon(18, 'n'), 'mdi:weather-rainy');
check('20 pluie et neige', meteobelgiqueirm::mdiIcon(20, 'd'), 'mdi:weather-snowy-rainy');
check('21 verglaçante', meteobelgiqueirm::mdiIcon(21, 'd'), 'mdi:weather-hail');
check('23 neige', meteobelgiqueirm::mdiIcon(23, 'd'), 'mdi:weather-snowy');
check('25 brouillard', meteobelgiqueirm::mdiIcon(25, 'd'), 'mdi:weather-fog');
check('code en chaîne', meteobelgiqueirm::mdiIcon('18', 'd'), 'mdi:weather-rainy');
check('code inconnu : ciel couvert', meteobelgiqueirm::mdiIcon(99, 'd'), 'mdi:weather-cloudy');
check('code absent : vide, l\'icône précédente reste', meteobelgiqueirm::mdiIcon(null, 'd'), '');
check('ciel sec et grand vent', meteobelgiqueirm::mdiIcon(0, 'd', 55), 'mdi:weather-windy');
check('couvert et grand vent', meteobelgiqueirm::mdiIcon(15, 'd', 55), 'mdi:weather-windy-variant');
check('sous le seuil, pas de vent', meteobelgiqueirm::mdiIcon(0, 'd', 49), 'mdi:weather-sunny');
check('la pluie l\'emporte sur le vent', meteobelgiqueirm::mdiIcon(18, 'd', 80), 'mdi:weather-rainy');

/* Chaque code connu de l'IRM a son icône, et c'est bien une icône météo. */
$complete = true;
foreach (array_keys(meteobelgiqueirm::WW) as $code) {
    foreach (array('d', 'n') as $dn) {
        $icon = meteobelgiqueirm::mdiIcon($code, $dn);
        if (strpos($icon, 'mdi:weather-') !== 0 || $icon === meteobelgiqueirm::MDI_UNKNOWN && !in_array($code, array(14, 15), true)) {
            $complete = false;
            echo '        -> code ' . $code . '/' . $dn . ' : ' . $icon . "\n";
        }
    }
}
check('les 28 codes connus ont une icône propre', $complete, true);
check('la table couvre la table des conditions',
    array_keys(meteobelgiqueirm::MDI), array_keys(meteobelgiqueirm::WW));

/* ======================================================= DONNÉES DU JOUR */
section('Données du jour');

$tzb = new DateTimeZone('Europe/Brussels');
$morning = (new DateTime('today 06:30', $tzb))->getTimestamp();
$todayKey = date('Y-m-d', $morning);

/* Une journée synthétique : le bloc jour de l'IRM, et des échéances horaires
 * de 6 h à 23 h. */
function dayModel($_day, $_hours, $_fetched) {
    global $todayKey, $tzb;
    $hourly = array();
    foreach ($_hours as $hour => $h) {
        $ts = (new DateTime($todayKey . ' ' . sprintf('%02d', $hour) . ':00', $tzb))->getTimestamp();
        $hourly[] = array_merge(array('ts' => $ts, 'temp' => null, 'ww' => null, 'day_night' => 'd',
            'rain_chance' => null, 'wind_speed' => null), $h);
    }
    return array(
        'fetched_at' => $_fetched,
        'city'       => 'Soignies',
        'obs'        => array('temp' => 9.0, 'ww' => 0, 'day_night' => 'n'),
        'days'       => array($todayKey => array_merge(array(
            'date' => $todayKey, 'tmin' => null, 'tmax' => null, 'ww' => null, 'rain_chance' => null,
            'text' => 'Temps variable.', 'd_tmin' => null, 'd_tmax' => null, 'd_ww' => null,
            'd_ww2' => null, 'd_rain' => null, 'd_wind' => null), $_day)),
        'hourly'     => $hourly,
        'warnings'   => array(),
    );
}

$hours = array();
for ($hh = 6; $hh <= 23; $hh++) {
    $hours[$hh] = array('temp' => 8.0 + ($hh < 15 ? $hh - 6 : 23 - $hh), 'ww' => 1, 'rain_chance' => 10.0, 'wind_speed' => 15.0);
}
$hours[23]['ww'] = 25;           // brouillard à 23 h : hors de la journée
$hours[16]['ww'] = 18;           // averse à 16 h : dans la journée
$hours[7]['temp'] = 3.0;         // le minimum du matin

$m = dayModel(array('d_tmin' => 4.0, 'd_tmax' => 17.0, 'd_ww' => 3, 'd_ww2' => 1, 'd_rain' => 40.0, 'd_wind' => 12.0),
    $hours, $morning - 60);
$t = invoke($eq, 'todayAt', array($m, $morning));
check('minimum lu dans le bloc jour', $t['tmin'], 4.0);
check('maximum lu dans le bloc jour', $t['tmax'], 17.0);
check('conditions du bloc jour', $t['ww'], 3);
check('risque de pluie du bloc jour', $t['rain_chance'], 40.0);
check('l\'averse de 16 h est dans les codes', in_array(18, $t['codes'], true), true);
check('le brouillard de 23 h n\'y est pas', in_array(25, $t['codes'], true), false);
check('le vent est le plus fort de la journée', $t['wind'], 15.0);
check('le bulletin rédigé suit', $t['text'], 'Temps variable.');

/* L'après-midi, l'IRM retire le minimum (relevé réel du 29/09/2026 à 12 h 44). */
$m = dayModel(array('d_tmin' => null, 'd_tmax' => 17.0, 'd_ww' => 3), $hours, $morning - 60);
$t = invoke($eq, 'todayAt', array($m, $morning));
check('minimum absent le matin : repli sur les heures avant midi', $t['tmin'], 3.0);
$afternoon = $morning + 7 * 3600;   // 13 h 30
$t = invoke($eq, 'todayAt', array($m, $afternoon));
check('minimum absent l\'après-midi : inconnu', $t['tmin'], null);

/* Le soir, le bloc jour a disparu. */
$m = dayModel(array(), $hours, $morning - 60);
$t = invoke($eq, 'todayAt', array($m, $morning));
check('maximum absent : repli sur le plus chaud prévu ou observé', $t['tmax'], 16.0);
check('risque absent : repli sur les heures', $t['rain_chance'], 10.0);
check('conditions absentes : pas de repli', $t['ww'], null);

/* Sur la réponse réelle de midi : le bloc jour est lu, le minimum manque. */
$raw = json_decode(file_get_contents(__DIR__ . '/fixtures/forecast_25121_midi.json'), true);
/* La réponse nomme le jour même par son nom anglais (« Tuesday ») : on le
 * recale sur le jour du lancement, sinon le test ne passerait que le mardi. */
$raw['for']['daily'][0]['dayName']['en'] = date('l');
$realDays = invoke($eq, 'parseDaily', array($raw));
$first = isset($realDays[date('Y-m-d')]) ? $realDays[date('Y-m-d')] : array();
check('réponse de midi : maximum du bloc jour', $first['d_tmax'], 27.0);
check('réponse de midi : minimum du bloc jour absent', $first['d_tmin'], null);
/* La fusion historique, elle, prend le minimum de la nuit qui vient : c'est
 * pourquoi le bulletin ne la lit pas. */
check('réponse de midi : la tuile garde le minimum de la nuit', $first['tmin'], 20.0);

/* ================================================= ENVOI DU BULLETIN */
section('Bulletin du matin : envoi unique');

$b = new meteobelgiqueirm();
$b->preSave();
check('le bulletin naît désactivé', (int) $b->getConfiguration('bulletin_enable'), 0);
check('heure par défaut', $b->getConfiguration('bulletin_time'), '06:30');
check('titre par défaut', $b->getConfiguration('bulletin_title'), 'Météo du jour');
check('message par défaut', $b->getConfiguration('bulletin_message'), '#min#°C - #max#°C | #conseils#');

$b->configuration['bulletin_cmds'] = '201,202';
$b->configuration['ins'] = '55040';
$model = dayModel(array('d_tmin' => 12.0, 'd_tmax' => 20.0, 'd_ww' => 18, 'd_rain' => 80.0, 'd_wind' => 20.0),
    $hours, $morning - 60);
invoke($b, 'saveForecast', array($model));

function bulletinAt($_eq, $_now) {
    cmd::$sent = array();
    scenarioExpression::$launched = array();
    $_eq->checkBulletin($_now);
    return cmd::$sent;
}

check('désactivé, rien ne part', count(bulletinAt($b, $morning)), 0);
$b->configuration['bulletin_enable'] = 1;
check('avant l\'heure, rien', count(bulletinAt($b, $morning - 600)), 0);
$sent = bulletinAt($b, $morning);
check('à l\'heure, il part vers les deux actions', count($sent), 2);
check('titre', $sent[0]['title'], 'Météo du jour');
check('message au format Home Assistant', $sent[0]['message'], '12°C - 20°C | Prenez un parapluie');
check('dix minutes plus tard, rien de plus', count(bulletinAt($b, $morning + 600)), 0);
check('une heure plus tard, rien de plus', count(bulletinAt($b, $morning + 3600)), 0);
/* Un redémarrage relit la mémoire : c'est le même cache que les alertes. */
$restarted = new meteobelgiqueirm();
$restarted->configuration = $b->configuration;
$restarted->store = $b->store;
check('après un redémarrage, toujours rien', count(bulletinAt($restarted, $morning + 4200)), 0);

/* Rattrapage : Jeedom éteint à 6 h 30, rallumé à 8 h. */
$late = new meteobelgiqueirm();
$late->configuration = $b->configuration;
invoke($late, 'saveForecast', array($model));
check('rallumé 1 h 30 après : il part', count(bulletinAt($late, $morning + 5400)), 2);
$later = new meteobelgiqueirm();
$later->configuration = $b->configuration;
invoke($later, 'saveForecast', array($model));
check('rallumé 2 h 10 après : trop tard, il se tait', count(bulletinAt($later, $morning + 7800)), 0);
check('fenêtre de deux heures, bornes comprises', count(bulletinAt($later, $morning + 7200)), 2);

/* Le lendemain, il repart. */
$tomorrowMorning = (new DateTime('tomorrow 06:30', $tzb))->getTimestamp();
$nextModel = $model;
$tomorrowKey = date('Y-m-d', $tomorrowMorning);
$nextModel['days'] = array($tomorrowKey => array_merge($model['days'][$todayKey], array('date' => $tomorrowKey)));
$nextModel['hourly'] = array();
invoke($b, 'saveForecast', array($nextModel));
check('le lendemain, il repart', count(bulletinAt($b, $tomorrowMorning)), 2);
invoke($b, 'saveForecast', array($model));

/* Jours de la semaine. */
check('jours : vide veut dire tous', meteobelgiqueirm::bulletinDays(''), array(1, 2, 3, 4, 5, 6, 7));
check('jours : 0 veut dire aucun', meteobelgiqueirm::bulletinDays('0'), array());
check('jours : semaine', meteobelgiqueirm::bulletinDays('12345'), array(1, 2, 3, 4, 5));
$weekday = (int) date('N', $morning);
$off = new meteobelgiqueirm();
$off->configuration = array_merge($b->configuration, array('bulletin_days' => (string) ($weekday % 7 + 1)));
invoke($off, 'saveForecast', array($model));
check('jour non coché : rien', count(bulletinAt($off, $morning)), 0);
$off->configuration['bulletin_days'] = (string) $weekday;
check('jour coché : il part', count(bulletinAt($off, $morning)), 2);

/* Condition. */
$c = new meteobelgiqueirm();
$c->configuration = array_merge($b->configuration, array('bulletin_condition' => '#5433# == 1'));
invoke($c, 'saveForecast', array($model));
jeedom::$results = array('#5433# == 1' => true);
check('condition vraie : il part', count(bulletinAt($c, $morning)), 2);

$c = new meteobelgiqueirm();
$c->configuration = array_merge($b->configuration, array('bulletin_condition' => '#5433# == 1'));
invoke($c, 'saveForecast', array($model));
jeedom::$results = array('#5433# == 1' => false);
check('condition fausse : rien', count(bulletinAt($c, $morning)), 0);
/* Fausse à 6 h 30, vraie à 7 h 30 : on ne rattrape pas, le jour est traité. */
jeedom::$results = array('#5433# == 1' => true);
check('devenue vraie plus tard : toujours rien', count(bulletinAt($c, $morning + 3600)), 0);

$c = new meteobelgiqueirm();
$c->configuration = array_merge($b->configuration, array('bulletin_condition' => '#5433# == 1'));
invoke($c, 'saveForecast', array($model));
jeedom::$results = array('#5433# == 1' => 1);
check('condition numérique 1 : il part', count(bulletinAt($c, $morning)), 2);

$c = new meteobelgiqueirm();
$c->configuration = array_merge($b->configuration, array('bulletin_condition' => '#5433# == 1'));
invoke($c, 'saveForecast', array($model));
jeedom::$results = array();   // non calculable : le coeur rend le texte
log::$lines = array();
check('condition non calculable : rien', count(bulletinAt($c, $morning)), 0);
$logged = false;
foreach (log::$lines as $line) {
    if (strpos($line, 'warning') === 0 && strpos($line, 'impossible à évaluer') !== false) { $logged = true; }
}
check('et le journal le signale', $logged, true);
log::$lines = array();
bulletinAt($c, $morning + 600);
check('une seule fois par jour', count(log::$lines), 0);
jeedom::$results = array();

/* Sans prévision du jour, on ne raconte pas n'importe quoi, et on retente. */
$empty = new meteobelgiqueirm();
$empty->configuration = $b->configuration;
/* Le cache du stub est commun à tous les équipements du jeu d'essai (même
 * identifiant) : on le vide explicitement. */
invoke($empty, 'clearForecast');
check('sans prévision : rien', count(bulletinAt($empty, $morning)), 0);
invoke($empty, 'saveForecast', array($model));
check('la prévision revenue, il part dans le créneau', count(bulletinAt($empty, $morning + 600)), 2);

/* Balises et minimum absent. */
$tags = new meteobelgiqueirm();
$tags->configuration = array_merge($b->configuration, array(
    'bulletin_title' => 'Météo à #commune#',
    'bulletin_message' => '#min#|#max#|#conditions#|#vent#|#pluie#|#bulletin#',
));
$afternoonModel = dayModel(array('d_tmin' => null, 'd_tmax' => 17.0, 'd_ww' => 3, 'd_rain' => 5.0, 'd_wind' => 12.0),
    $hours, $afternoon - 60);
$composed = $tags->composeBulletin($afternoonModel, $afternoon);
check('balise #commune# dans le titre', $composed['title'], 'Météo à Soignies');
check('minimum absent : balise vide', $composed['message'], '|17|Partiellement nuageux|15|5|Temps variable.');

/* En parallèle : l'action est confiée au coeur, pas exécutée ici. */
$bg = new meteobelgiqueirm();
$bg->configuration = array_merge($b->configuration, array('bulletin_background' => 1));
invoke($bg, 'saveForecast', array($model));
$sent = bulletinAt($bg, $morning);
check('en parallèle : rien d\'exécuté directement', count($sent), 0);
check('en parallèle : deux actions confiées au coeur', count(scenarioExpression::$launched), 2);
check('en parallèle : option background', scenarioExpression::$launched[0]['options']['background'], 1);
check('en parallèle : commande au format du coeur', scenarioExpression::$launched[0]['cmd'], '#201#');

/* Enregistrer dans le créneau ne fait pas partir le bulletin. */
$settled = new meteobelgiqueirm();
$settled->configuration = array_merge($b->configuration, array('bulletin_time' => date('H:i', time() - 600)));
invoke($settled, 'saveForecast', array($model));
invoke($settled, 'settleBulletin');
check('enregistré dans le créneau : marqué sans envoi', count(bulletinAt($settled, time())), 0);

/* Heure invalide : pas de créneau, pas d'erreur. */
$bad = new meteobelgiqueirm();
$bad->configuration = array_merge($b->configuration, array('bulletin_time' => '25:99'));
check('heure invalide : aucun créneau', $bad->bulletinSlot($morning), null);

/* Un bulletin réglé à 23 h 30 et manqué part encore après minuit. */
$night = new meteobelgiqueirm();
$night->configuration = array_merge($b->configuration, array('bulletin_time' => '23:30'));
$yesterdayLate = (new DateTime('yesterday 23:30', $tzb))->getTimestamp();
check('créneau de la veille retrouvé après minuit',
    $night->bulletinSlot($yesterdayLate + 3000), date('Y-m-d', $yesterdayLate));

/* ================================================================== BILAN */
echo "\n" . str_repeat('=', 72) . "\n";
printf("%d réussis, %d échoués\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
