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

/* ================================================================== BILAN */
echo "\n" . str_repeat('=', 72) . "\n";
printf("%d réussis, %d échoués\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
