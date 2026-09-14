<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

class meteobelgiqueirm extends eqLogic {

    /*
     * L'IRM ne publie pas d'API de prévision par commune. Celle-ci est celle de
     * son application mobile : non documentée, sans contrat de service, et elle
     * a changé trois fois en deux ans (pollens en juin 2024 et janvier 2025,
     * disparition de champs en janvier 2026). Tout ce qui en sort est donc lu
     * avec des gardes, et une rupture doit dégrader l'affichage, jamais casser
     * l'équipement.
     */
    const API_BASE = 'https://app.meteo.be/services/appv4/';

    /*
     * La clé change chaque jour à minuit : elle inclut la date locale. La mettre
     * en cache ferait échouer le premier appel qui suit minuit — elle est donc
     * recalculée à chaque requête, ce qui ne coûte qu'un md5.
     */
    const API_SECRET = 'r9EnW374jkJ9acc';

    /*
     * Durée de conservation de la dernière réponse connue.
     *
     * Ce n'est PAS un minuteur de fraîcheur : la fraîcheur est portée par
     * fetched_at, par la commande data_age et par le timeout du coeur. C'est un
     * magasin de secours, et il doit survivre à une panne longue — sinon le
     * principe « une panne n'efface jamais l'acquis » est faux dès que l'IRM
     * tombe plus longtemps que le TTL.
     *
     * Ce délai valait une demi-heure au départ, et ça se voyait : une coupure de
     * trente-et-une minutes vidait la tuile au lieu d'afficher des valeurs
     * vieillissantes. Deux jours laissent le temps de réparer une box éteinte
     * pour le week-end ; au-delà, mieux vaut effectivement ne plus rien affirmer.
     */
    const FORECAST_TTL = 172800;

    /*
     * plugin::cron10() dispose de dix minutes pour TOUS les plugins de cette
     * fréquence, exécutés en série dans un seul processus. Une commune coûte
     * environ une seconde : le budget n'est pas là pour nous, il est là pour
     * qu'une panne de DNS sur vingt communes ne gèle pas les autres plugins.
     */
    const CRON_BUDGET = 60;

    /*
     * Un scénario qui appellerait « Rafraîchir » en boucle ferait de ce plugin
     * un client abusif d'un service gratuit. Au-delà de cette cadence, la
     * commande recompose l'affichage depuis le cache sans toucher au réseau.
     */
    const FORCE_MIN_INTERVAL = 300;

    /* Recul après échec : dix minutes doublées à chaque fois, plafond une heure.
     * Une commune devenue invalide ne coûte plus que vingt-quatre requêtes par
     * jour au lieu de cent quarante-quatre. */
    const BACKOFF_MIN = 600;
    const BACKOFF_MAX = 3600;

    /*
     * Trois passages manqués plus une marge. Au-delà, le coeur marque
     * l'équipement en timeout, l'affiche comme tel sur le dashboard et la page
     * Santé et poste un message — à condition qu'on publie les valeurs avec
     * leur vraie date de lecture (voir publishCmd).
     */
    const TIMEOUT_MINUTES = 45;

    /*
     * Bande horaire de la tuile.
     *
     * Quatorze colonnes tenaient dans 300 px, mais à vingt-et-un pixels chacune
     * l'icône tombait sous les dix pixels : affichée, et pourtant invisible. Il
     * en tient huit confortablement, à trente-sept pixels.
     *
     * On couvre donc la même amplitude avec moins de colonnes, en regroupant
     * les heures. Regrouper ne doit pas faire disparaître une averse : chaque
     * colonne porte le risque de pluie MAXIMUM de l'intervalle qu'elle
     * représente, jamais celui de sa seule première heure. La température, elle,
     * reste celle de l'heure affichée.
     *
     * En dessous de six heures restantes — c'est-à-dire en soirée — la bande
     * n'apprendrait plus rien : on déborde alors sur la nuit et le lendemain,
     * en marquant le changement de jour.
     */
    const HOURS_COLUMNS = 8;
    const HOURS_SPAN = 16;
    const HOURS_MIN = 6;

    /*
     * Valeur d'une commande numérique quand l'information est inconnue. Zéro ne
     * convient pas : sur un niveau d'avertissement, zéro veut dire « aucun
     * avertissement », ce qui est une affirmation. Et le coeur convertit une
     * chaîne vide en zéro sans prévenir.
     */
    const UNKNOWN = -1;

    /*
     * Conditions météo de l'API, indexées par le code ww puis par la période.
     * Chaque entrée : libellé français, code WeatherAPI attendu par les widgets
     * tiers, icône Font Awesome.
     *
     * Le jour et la nuit ne diffèrent que pour 0 et 1 : au-delà, l'API rend la
     * même condition, et le jeu d'icônes officiel confond d'ailleurs plusieurs
     * codes entre eux (2/5/7, 4/6, 8/9, 11/12, 16/19). Distinguer leur
     * intensité serait inventer une information que l'IRM ne donne pas.
     */
    const WW = array(
        0  => array('d' => array('Ensoleillé',                          1000, 'fas fa-sun'),
                    'n' => array('Ciel dégagé',                         1000, 'fas fa-moon')),
        1  => array('d' => array('Peu nuageux',                         1003, 'fas fa-cloud-sun'),
                    'n' => array('Peu nuageux',                         1003, 'fas fa-cloud-moon')),
        2  => array('d' => array('Averses orageuses',                   1273, 'fas fa-poo-storm')),
        3  => array('d' => array('Partiellement nuageux',               1003, 'fas fa-cloud-sun')),
        4  => array('d' => array('Averses',                             1243, 'fas fa-cloud-showers-heavy')),
        5  => array('d' => array('Averses orageuses',                   1273, 'fas fa-poo-storm')),
        6  => array('d' => array('Averses',                             1243, 'fas fa-cloud-showers-heavy')),
        7  => array('d' => array('Averses orageuses',                   1273, 'fas fa-poo-storm')),
        8  => array('d' => array('Averses de pluie et neige mêlées',    1249, 'fas fa-cloud-meatball')),
        9  => array('d' => array('Averses de pluie et neige mêlées',    1249, 'fas fa-cloud-meatball')),
        10 => array('d' => array('Averses orageuses de neige fondante', 1273, 'fas fa-poo-storm')),
        11 => array('d' => array('Averses de neige',                    1255, 'fas fa-snowflake')),
        12 => array('d' => array('Averses de neige',                    1255, 'fas fa-snowflake')),
        13 => array('d' => array('Averses de neige orageuses',          1279, 'fas fa-poo-storm')),
        14 => array('d' => array('Nuageux',                             1006, 'fas fa-cloud')),
        15 => array('d' => array('Couvert',                             1009, 'fas fa-cloud')),
        16 => array('d' => array('Pluie forte',                         1195, 'fas fa-cloud-showers-heavy')),
        17 => array('d' => array('Pluie orageuse',                      1276, 'fas fa-poo-storm')),
        18 => array('d' => array('Pluie',                               1189, 'fas fa-cloud-rain')),
        19 => array('d' => array('Pluie forte',                         1195, 'fas fa-cloud-showers-heavy')),
        20 => array('d' => array('Pluie et neige mêlées',               1207, 'fas fa-cloud-meatball')),
        21 => array('d' => array('Pluie verglaçante',                   1198, 'fas fa-icicles')),
        22 => array('d' => array('Neige faible',                        1213, 'fas fa-snowflake')),
        23 => array('d' => array('Neige',                               1219, 'fas fa-snowflake')),
        24 => array('d' => array('Brume avec éclaircies',               1030, 'fas fa-smog')),
        25 => array('d' => array('Brouillard',                          1135, 'fas fa-smog')),
        26 => array('d' => array('Brume',                               1030, 'fas fa-smog')),
        27 => array('d' => array('Brouillard givrant',                  1147, 'fas fa-smog')),
    );

    /*
     * Types d'avertissement, indexés par warningType.id.
     * Chaque entrée : identifiant stable pour les scénarios, libellé de repli.
     *
     * Le libellé n'est qu'un repli : le nom affiché vient de la réponse, car il
     * dépend du pays — l'identifiant 15 vaut « Marée forte » en Belgique et
     * « Crue » au Luxembourg. L'identifiant, lui, ne bouge pas et ne se traduit
     * pas : c'est sur lui qu'un scénario doit être écrit.
     *
     * Les identifiants 4, 5, 6, 8, 11 et 16 n'ont jamais été observés. Un
     * identifiant inconnu ne doit pas produire une chaîne vide, qui dirait
     * « pas d'avertissement » : il produit « warning_<id> », qui reste stable.
     */
    const WARNING_TYPES = array(
        0  => array('wind',                        'Vent'),
        1  => array('rain',                        'Pluie'),
        2  => array('ice_or_snow',                 'Conditions glissantes'),
        3  => array('thunder',                     'Orage'),
        7  => array('fog',                         'Brouillard'),
        9  => array('cold',                        'Froid'),
        10 => array('heat',                        'Chaleur'),
        12 => array('thunder_wind_rain',           'Orage, rafales et averses'),
        13 => array('thunderstorm_strong_gusts',   'Orage et rafales'),
        14 => array('thunderstorm_large_rainfall', 'Orage et averses'),
        15 => array('storm_surge',                 'Marée forte'),
        17 => array('coldspell',                   'Vague de froid'),
    );

    /* L'IRM n'a que trois niveaux ; le zéro est notre convention pour « rien ». */
    const WARNING_LEVELS = array(
        0 => 'Aucun avertissement',
        1 => 'Vigilance jaune',
        2 => 'Vigilance orange',
        3 => 'Vigilance rouge',
    );

    /*
     * Message d'échec de la dernière lecture, pour que la commande d'action
     * « Rafraîchir » puisse le relayer à un scénario. Le souligné initial n'est
     * pas décoratif : DB::save() traite toute propriété qui n'en a pas comme une
     * colonne de la table et fait échouer la création de l'équipement sur
     * « Unknown column ».
     */
    private $_refreshError = '';

    /* ============================================================== WIDGETS */

    /*
     * Le coeur remplace les guillemets doubles par des apostrophes dans le code
     * du gabarit (cmd::getWidgetTemplateCode) : on écrit donc directement en
     * apostrophes.
     */
    public static function templateWidget() {
        $icons = array(
            '#_icon_on_#'  => "<i class='icon_red fas fa-exclamation-triangle'></i>",
            '#_icon_off_#' => "<i class='icon_green fas fa-check'></i>",
        );
        return array('info' => array('binary' => array(
            'warning' => array('template' => 'tmplicon', 'replace' => $icons),
            'rain'    => array('template' => 'tmplicon', 'replace' => array(
                '#_icon_on_#'  => "<i class='icon_blue fas fa-umbrella'></i>",
                '#_icon_off_#' => "<i class='icon_green fas fa-sun'></i>",
            )),
        )));
    }

    /* ================================================================= CRON */

    /*
     * Dix minutes, c'est le pas des observations de l'IRM et celui de sa
     * séquence radar : interroger plus souvent ne rapporterait rien de neuf.
     */
    public static function cron10() {
        $deadline = microtime(true) + self::CRON_BUDGET;

        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            try {
                if (!$eqLogic->shouldPoll() || microtime(true) > $deadline) {
                    /*
                     * Même sans appel réseau, il faut repasser sur les
                     * commandes : un avertissement annoncé pour 14 h devient
                     * actif tout seul, le décompte avant la pluie s'épuise, et
                     * le jour change à minuit. Sans ce geste, « pluie dans
                     * 20 min » resterait affiché une heure plus tard.
                     */
                    $eqLogic->refreshFromCache();
                    continue;
                }
                $eqLogic->update();
            } catch (Throwable $e) {
                /* Une commune en échec ne doit pas priver les autres de leur tour. */
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /* ================================================ CYCLE DE VIE eqLogic */

    /*
     * Aucune exception ici. Le coeur crée l'équipement avec son seul nom : une
     * validation stricte rendrait le bouton « Ajouter » définitivement
     * inopérant, sans message d'erreur exploitable.
     */
    public function preSave() {
        if ($this->getId() == '') {
            /* Le formulaire de création n'envoie que le nom : sans cela,
             * l'équipement naît désactivé et invisible. */
            $this->setIsEnable(1);
            $this->setIsVisible(1);
            $this->setDisplay('width', '300px');
        }

        /*
         * Le coeur marque l'équipement en défaut au-delà de ce délai sans
         * communication. Posé une seule fois : si l'utilisateur l'ajuste
         * ensuite, c'est son choix.
         */
        if ((int) $this->getTimeout() === 0) {
            $this->setTimeout(self::TIMEOUT_MINUTES);
        }
    }

    public function postSave() {
        $this->createCommands();

        if (!$this->isConfigured()) {
            return;
        }

        /*
         * Lecture immédiate, pour que l'utilisateur voie ses données à la
         * seconde où il enregistre et non au prochain cron. Mais une IRM
         * indisponible ne doit pas faire échouer l'enregistrement : la commune
         * est enregistrée, les valeurs viendront plus tard.
         */
        try {
            $changed = ($this->getCache('signature', '') !== $this->signature());
            if ($changed) {
                $this->setCache('signature', $this->signature());
                $this->clearFailure();
            }
            $this->update($changed);
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $e->getMessage());
        }
    }

    /* DB::remove() met l'id à null avant postRemove : le nettoyage se fait ici,
     * tant que les clés de cache sont encore calculables. */
    public function preRemove() {
        try {
            $this->clearForecast();
            $this->clearProblem();
        } catch (Throwable $e) {
            log::add(__CLASS__, 'debug', __('Nettoyage impossible :', __FILE__) . ' ' . $e->getMessage());
        }
        return true;
    }

    /* Une commune est configurée dès qu'un code INS est choisi. */
    public function isConfigured() {
        return trim((string) $this->getConfiguration('ins', '')) !== '';
    }

    /*
     * Ce qui, en changeant, oblige à tout relire. Le nom de l'équipement ou son
     * icône n'en font pas partie : les modifier ne doit pas consommer une
     * requête chez l'IRM.
     */
    public function signature() {
        return trim((string) $this->getConfiguration('ins', ''));
    }

    /* ============================================================ COMMANDES */

    /*
     * Création idempotente. On ne récrit jamais une commande existante : le nom,
     * la visibilité et l'historisation appartiennent à l'utilisateur dès qu'il y
     * a touché.
     */
    private function addCmdIfMissing($_logicalId, $_name, $_type, $_subType, $_options = array()) {
        $cmd = $this->getCmd(null, $_logicalId);
        if (is_object($cmd)) {
            return $cmd;
        }

        $cmd = new meteobelgiqueirmCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setLogicalId($_logicalId);

        /* Unicité (eqLogic_id, name) en base : un nom déjà pris ferait échouer
         * tout l'enregistrement, pas seulement cette commande. */
        $name = __($_name, __FILE__);
        if (is_object(cmd::byEqLogicIdCmdName($this->getId(), $name))) {
            $name .= ' (' . $_logicalId . ')';
        }
        $cmd->setName($name);
        $cmd->setType($_type);
        $cmd->setSubType($_subType);
        $cmd->setIsVisible(isset($_options['isVisible']) ? $_options['isVisible'] : 0);
        $cmd->setIsHistorized(isset($_options['isHistorized']) ? $_options['isHistorized'] : 0);

        if (isset($_options['order']))    { $cmd->setOrder($_options['order']); }
        if (isset($_options['unite']))    { $cmd->setUnite($_options['unite']); }
        if (isset($_options['generic']))  { $cmd->setGeneric_type($_options['generic']); }
        if (isset($_options['icon']))     { $cmd->setDisplay('icon', '<i class="' . $_options['icon'] . '"></i>'); }
        if (isset($_options['template'])) {
            $cmd->setTemplate('dashboard', $_options['template']);
            $cmd->setTemplate('mobile', $_options['template']);
        }
        $cmd->save();
        return $cmd;
    }

    public function createCommands() {
        $order = 0;

        /* --- La tuile : seule commande visible par défaut. Quatorze widgets
         * empilés rendent un dashboard illisible ; la météo est une question
         * unique, une tuile y répond en entier. --- */
        $this->addCmdIfMissing('resume', 'Météo', 'info', 'string', array(
            'order' => $order++, 'isVisible' => 1,
            'template' => __CLASS__ . '::' . __CLASS__,
        ));

        /* --- Instant présent --- */
        $this->addCmdIfMissing('temperature', 'Température', 'info', 'numeric', array(
            'order' => $order++, 'unite' => '°C', 'isHistorized' => 1,
            'generic' => 'WEATHER_TEMPERATURE', 'icon' => 'fas fa-thermometer-half',
        ));
        $this->addCmdIfMissing('condition', 'Conditions', 'info', 'string', array(
            'order' => $order++, 'generic' => 'WEATHER_CONDITION',
        ));
        $this->addCmdIfMissing('condition_id', 'Code conditions', 'info', 'numeric', array(
            'order' => $order++, 'generic' => 'WEATHER_CONDITION_ID',
        ));
        $this->addCmdIfMissing('day_night', 'Jour ou nuit', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('pressure', 'Pression', 'info', 'numeric', array(
            'order' => $order++, 'unite' => 'hPa', 'isHistorized' => 1,
            'generic' => 'WEATHER_PRESSURE', 'icon' => 'fas fa-tachometer-alt',
        ));
        $this->addCmdIfMissing('wind_speed', 'Vent', 'info', 'numeric', array(
            'order' => $order++, 'unite' => 'km/h', 'isHistorized' => 1,
            'generic' => 'WEATHER_WIND_SPEED', 'icon' => 'fas fa-wind',
        ));
        $this->addCmdIfMissing('wind_gust', 'Rafales', 'info', 'numeric', array(
            'order' => $order++, 'unite' => 'km/h', 'icon' => 'fas fa-wind',
        ));
        $this->addCmdIfMissing('wind_direction', 'Direction du vent', 'info', 'numeric', array(
            'order' => $order++, 'unite' => '°', 'generic' => 'WEATHER_WIND_DIRECTION',
        ));
        $this->addCmdIfMissing('wind_direction_text', 'Direction du vent (lettres)', 'info', 'string', array(
            'order' => $order++,
        ));
        $this->addCmdIfMissing('uv', 'Indice UV', 'info', 'numeric', array(
            'order' => $order++, 'icon' => 'fas fa-sun',
        ));

        /*
         * Lever et coucher en entier HMM (732 pour 7 h 32), pas en texte : c'est
         * la convention du coeur, celle qu'attendent les scénarios qui comparent
         * à date('Gi'), et les deux types génériques sont numériques.
         */
        $this->addCmdIfMissing('sunrise', 'Lever du soleil', 'info', 'numeric', array(
            'order' => $order++, 'generic' => 'WEATHER_SUNRISE', 'icon' => 'fas fa-sun',
        ));
        $this->addCmdIfMissing('sunset', 'Coucher du soleil', 'info', 'numeric', array(
            'order' => $order++, 'generic' => 'WEATHER_SUNSET', 'icon' => 'fas fa-moon',
        ));

        $this->addCmdIfMissing('city', 'Commune', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('obs_time', 'Heure de l\'observation', 'info', 'string', array('order' => $order++));

        /*
         * L'âge est la seule commande qui dise si l'on regarde une vraie donnée
         * ou un souvenir. Les seuils ne sont posés qu'à la création : au-delà,
         * ils appartiennent à l'utilisateur.
         */
        $age = $this->addCmdIfMissing('data_age', 'Âge des données', 'info', 'numeric', array(
            'order' => $order++, 'unite' => 'min', 'icon' => 'fas fa-clock',
        ));
        if ($age->getAlert('warningif') == '' && $age->getAlert('dangerif') == '') {
            $age->setAlert('warningif', '#value# >= 30');
            $age->setAlert('dangerif', '#value# >= 60');
            $age->save();
        }

        /* --- Pluie à courte échéance : le bloc le plus utile en domotique, et
         * le moins cher puisqu'il arrive dans la même réponse. --- */
        $this->addCmdIfMissing('rain_now', 'Pluie actuelle', 'info', 'numeric', array(
            'order' => $order++, 'unite' => 'mm/h', 'isHistorized' => 1,
            'generic' => 'RAIN_CURRENT', 'icon' => 'fas fa-cloud-rain',
        ));
        $this->addCmdIfMissing('rain_next', 'Pluie dans', 'info', 'numeric', array(
            'order' => $order++, 'unite' => 'min', 'icon' => 'fas fa-umbrella',
        ));
        $this->addCmdIfMissing('rain_soon', 'Pluie prochainement', 'info', 'binary', array(
            'order' => $order++, 'template' => 'rain',
        ));
        $this->addCmdIfMissing('rain_hint', 'Résumé pluie', 'info', 'string', array('order' => $order++));

        /* --- Avertissements --- */
        $this->addCmdIfMissing('warning_active', 'Avertissement actif', 'info', 'binary', array(
            'order' => $order++, 'template' => 'warning',
        ));
        $level = $this->addCmdIfMissing('warning_level', 'Niveau d\'avertissement', 'info', 'numeric', array(
            'order' => $order++, 'isHistorized' => 1, 'icon' => 'fas fa-exclamation-triangle',
        ));
        /* Ces seuils ne colorent pas la tuile — le gabarit s'en charge — mais ils
         * alimentent gratuitement l'icône d'alerte de l'équipement et la page
         * Santé. Le seuil bas est à 1 pour ne pas déclencher sur -1 (inconnu). */
        if ($level->getAlert('warningif') == '' && $level->getAlert('dangerif') == '') {
            $level->setAlert('warningif', '#value# == 1');
            $level->setAlert('dangerif', '#value# >= 2');
            $level->save();
        }
        $this->addCmdIfMissing('warning_slug', 'Type d\'avertissement', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('warning_slugs', 'Types d\'avertissement', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('warning_label', 'Avertissement', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('warning_text', 'Texte de l\'avertissement', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('warning_end', 'Fin de l\'avertissement', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('warning_count', 'Nombre d\'avertissements', 'info', 'numeric', array('order' => $order++));
        $this->addCmdIfMissing('next_warning_level', 'Niveau du prochain avertissement', 'info', 'numeric', array('order' => $order++));
        $this->addCmdIfMissing('next_warning_slug', 'Type du prochain avertissement', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('next_warning_start', 'Début du prochain avertissement', 'info', 'string', array('order' => $order++));

        /* --- Bulletins rédigés : aujourd'hui et demain. Au-delà, personne ne
         * les lit, et ils ne sont pas historisables (history.value est un
         * varchar(127)). --- */
        $this->addCmdIfMissing('bulletin_0', 'Bulletin du jour', 'info', 'string', array('order' => $order++));
        $this->addCmdIfMissing('bulletin_1', 'Bulletin de demain', 'info', 'string', array('order' => $order++));

        /* --- Prévisions journalières. Les types génériques du coeur s'arrêtent
         * à J+4 : au-delà, les commandes existent et fonctionnent, elles ne sont
         * simplement pas reconnues sémantiquement. --- */
        for ($i = 1; $i <= 7; $i++) {
            $this->addCmdIfMissing('temperature_' . $i . '_min', 'Température min J+' . $i, 'info', 'numeric', array(
                'order' => $order++, 'unite' => '°C',
                'generic' => ($i <= 4) ? 'WEATHER_TEMPERATURE_MIN_' . $i : null,
            ));
            $this->addCmdIfMissing('temperature_' . $i . '_max', 'Température max J+' . $i, 'info', 'numeric', array(
                'order' => $order++, 'unite' => '°C',
                'generic' => ($i <= 4) ? 'WEATHER_TEMPERATURE_MAX_' . $i : null,
            ));
            $this->addCmdIfMissing('condition_' . $i, 'Conditions J+' . $i, 'info', 'string', array(
                'order' => $order++,
                'generic' => ($i <= 4) ? 'WEATHER_CONDITION_' . $i : null,
            ));
            if ($i <= 4) {
                $this->addCmdIfMissing('condition_id_' . $i, 'Code conditions J+' . $i, 'info', 'numeric', array(
                    'order' => $order++, 'generic' => 'WEATHER_CONDITION_ID_' . $i,
                ));
            }
            if ($i <= 3) {
                $this->addCmdIfMissing('rain_chance_' . $i, 'Risque de pluie J+' . $i, 'info', 'numeric', array(
                    'order' => $order++, 'unite' => '%',
                ));
            }
        }

        /* --- Prévisions horaires H+1 à H+3, comme le plugin Météo officiel. --- */
        for ($i = 1; $i <= 3; $i++) {
            $this->addCmdIfMissing('temperature_h' . $i, 'Température H+' . $i, 'info', 'numeric', array(
                'order' => $order++, 'unite' => '°C',
            ));
            $this->addCmdIfMissing('condition_h' . $i, 'Conditions H+' . $i, 'info', 'string', array('order' => $order++));
            $this->addCmdIfMissing('rain_chance_h' . $i, 'Risque de pluie H+' . $i, 'info', 'numeric', array(
                'order' => $order++, 'unite' => '%',
            ));
        }

        /* --- Action --- */
        $this->addCmdIfMissing('refresh', 'Rafraîchir', 'action', 'other', array(
            'order' => $order++, 'icon' => 'fas fa-sync',
        ));
    }

    /*
     * Appelée par plugin_info/install.php à chaque mise à jour du plugin.
     * Volontairement hors ligne : le coeur exécute la mise à jour dans la
     * requête HTTP sans détacher le processus, et un postSave qui interrogerait
     * l'IRM ferait partir la page en timeout — en écrivant au passage des
     * valeurs de repli qui réveilleraient les scénarios de l'utilisateur.
     */
    public static function rebuildCommands() {
        $count = 0;
        foreach (self::byType(__CLASS__) as $eqLogic) {
            try {
                $eqLogic->createCommands();
                $eqLogic->refreshWidget();
                $count++;
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
        return $count;
    }

    /* ============================================================== LECTURE */

    /*
     * Point d'entrée unique de la lecture. Une panne n'efface jamais l'acquis :
     * en cas d'échec on recompose depuis le cache et on signale, on ne vide pas.
     */
    public function update($_force = false) {
        $this->_refreshError = '';

        if (!$this->isConfigured()) {
            return;
        }

        /*
         * Un scénario qui appellerait « Rafraîchir » en boucle recompose depuis
         * le cache sans toucher au réseau.
         *
         * Mais seulement s'il y a quelque chose à recomposer : sans cette
         * réserve, une commune qui n'a jamais réussi une lecture — ou dont le
         * cache a expiré — ne peut plus jamais s'amorcer. Le bouton rend la
         * main sans rien faire et sans rien dire, ce qui est le pire des deux
         * mondes. Le garde-fou protège le service de l'IRM, il n'a pas à
         * empêcher un premier relevé.
         */
        if ($_force) {
            $last = (int) $this->getCache('lastForce', 0);
            if ($last > 0 && (time() - $last) < self::FORCE_MIN_INTERVAL && !empty($this->getForecast())) {
                $this->refreshFromCache();
                return;
            }
            $this->setCache('lastForce', time());
        }

        try {
            $raw = self::call('getForecasts', array('ins' => $this->signature()));
            $model = $this->buildModel($raw);
            $this->saveForecast($model);
            $this->clearFailure();
            $this->clearProblem();
            $this->refreshCommands($model);
        } catch (Throwable $e) {
            $this->_refreshError = $e->getMessage();
            $this->noteFailure();
            $this->reportProblem($e->getMessage());
            /* On republie ce qu'on sait, daté de sa vraie heure de lecture :
             * l'utilisateur voit des valeurs vieillissantes, jamais du vide. */
            $this->refreshFromCache();
        }
    }

    /* Recompose l'affichage sans aucun appel réseau. */
    public function refreshFromCache() {
        $model = $this->getForecast();
        if (!empty($model)) {
            $this->refreshCommands($model);
        }
    }

    /*
     * Écrit toutes les commandes depuis le modèle. Un seul point d'écriture :
     * c'est ce qui garantit qu'une valeur ne peut pas être publiée sans sa date.
     */
    private function refreshCommands($_model) {
        $fetchedAt = isset($_model['fetched_at']) ? (int) $_model['fetched_at'] : 0;
        $when = $fetchedAt > 0 ? date('Y-m-d H:i:s', $fetchedAt) : null;
        $now = time();

        /* --- Instant présent --- */
        $obs = isset($_model['obs']) ? $_model['obs'] : array();
        $cur = isset($_model['current']) ? $_model['current'] : array();

        $this->publishCmd('temperature', isset($obs['temp']) ? $obs['temp'] : null, $when);
        $this->publishCmd('day_night', isset($obs['day_night']) ? $obs['day_night'] : '', $when);

        $ww = self::describeWw(isset($obs['ww']) ? $obs['ww'] : null, isset($obs['day_night']) ? $obs['day_night'] : 'd');
        $this->publishCmd('condition', $ww[0], $when);
        $this->publishCmd('condition_id', $ww[1], $when);

        $this->publishCmd('pressure', isset($cur['pressure']) ? $cur['pressure'] : null, $when);
        $this->publishCmd('wind_speed', isset($cur['wind_speed']) ? $cur['wind_speed'] : null, $when);
        $this->publishCmd('wind_gust', isset($cur['wind_gust']) ? $cur['wind_gust'] : null, $when);
        $this->publishCmd('wind_direction', isset($cur['wind_direction']) ? $cur['wind_direction'] : null, $when);
        $this->publishCmd('wind_direction_text', isset($cur['wind_direction_text']) ? $cur['wind_direction_text'] : '', $when);
        $this->publishCmd('uv', isset($_model['uv']) ? $_model['uv'] : null, $when);
        $this->publishCmd('city', isset($_model['city']) ? $_model['city'] : '', $when);
        $this->publishCmd('obs_time', isset($obs['time']) ? $obs['time'] : '', $when);

        /* --- Lever et coucher du jour, en entier HMM --- */
        $today = $this->dayAt($_model, 0);
        $this->publishCmd('sunrise', isset($today['sunrise']) ? $today['sunrise'] : null, $when);
        $this->publishCmd('sunset', isset($today['sunset']) ? $today['sunset'] : null, $when);

        /*
         * L'âge se recalcule à chaque passage, même sans lecture réseau : c'est
         * lui qui dit à l'utilisateur qu'il regarde un souvenir. Il change donc
         * à chaque cron, ce qui est voulu — et c'est la seule commande dans ce
         * cas, précisément parce qu'elle mesure le temps.
         */
        $this->publishCmd('data_age', $fetchedAt > 0 ? (int) floor(($now - $fetchedAt) / 60) : self::UNKNOWN, $when);

        /* --- Pluie à courte échéance --- */
        $nowcast = $this->nowcastAt($_model, $now);
        $this->publishCmd('rain_now', $nowcast['now'], $when);
        $this->publishCmd('rain_next', $nowcast['next'], $when);
        $this->publishCmd('rain_soon', $nowcast['soon'], $when);
        $this->publishCmd('rain_hint', $nowcast['hint'], $when);

        /* --- Avertissements --- */
        $w = $this->warningsAt($_model, $now);
        $this->publishCmd('warning_active', $w['active'], $when);
        $this->publishCmd('warning_level', $w['level'], $when);
        $this->publishCmd('warning_slug', $w['slug'], $when);
        $this->publishCmd('warning_slugs', $w['slugs'], $when);
        $this->publishCmd('warning_label', $w['label'], $when);
        $this->publishCmd('warning_text', $w['text'], $when);
        $this->publishCmd('warning_end', $w['end'], $when);
        $this->publishCmd('warning_count', $w['count'], $when);
        $this->publishCmd('next_warning_level', $w['next_level'], $when);
        $this->publishCmd('next_warning_slug', $w['next_slug'], $when);
        $this->publishCmd('next_warning_start', $w['next_start'], $when);

        /* --- Bulletins --- */
        $this->publishCmd('bulletin_0', isset($today['text']) ? $today['text'] : '', $when);
        $tomorrow = $this->dayAt($_model, 1);
        $this->publishCmd('bulletin_1', isset($tomorrow['text']) ? $tomorrow['text'] : '', $when);

        /* --- Prévisions journalières --- */
        for ($i = 1; $i <= 7; $i++) {
            $d = $this->dayAt($_model, $i);
            $this->publishCmd('temperature_' . $i . '_min', isset($d['tmin']) ? $d['tmin'] : null, $when);
            $this->publishCmd('temperature_' . $i . '_max', isset($d['tmax']) ? $d['tmax'] : null, $when);
            $dw = self::describeWw(isset($d['ww']) ? $d['ww'] : null, 'd');
            $this->publishCmd('condition_' . $i, $dw[0], $when);
            if ($i <= 4) {
                $this->publishCmd('condition_id_' . $i, $dw[1], $when);
            }
            if ($i <= 3) {
                $this->publishCmd('rain_chance_' . $i, isset($d['rain_chance']) ? $d['rain_chance'] : null, $when);
            }
        }

        /* --- Prévisions horaires --- */
        $hourly = isset($_model['hourly']) ? $_model['hourly'] : array();
        $future = array();
        foreach ($hourly as $h) {
            if ($h['ts'] > $now) { $future[] = $h; }
        }
        for ($i = 1; $i <= 3; $i++) {
            $h = isset($future[$i - 1]) ? $future[$i - 1] : array();
            $this->publishCmd('temperature_h' . $i, isset($h['temp']) ? $h['temp'] : null, $when);
            $hw = self::describeWw(isset($h['ww']) ? $h['ww'] : null, isset($h['day_night']) ? $h['day_night'] : 'd');
            $this->publishCmd('condition_h' . $i, $hw[0], $when);
            $this->publishCmd('rain_chance_h' . $i, isset($h['rain_chance']) ? $h['rain_chance'] : null, $when);
        }

        /* --- La tuile --- */
        $this->publishCmd('resume', $this->buildResume($_model, $w, $nowcast, $ww, $now), $when);

        $this->refreshWidget();
    }

    /*
     * Écriture d'une commande. Le nom n'est pas anodin : une méthode appelée
     * setCmd() serait invoquée par utils::a2o() à chaque enregistrement de la
     * page — le formulaire envoie toujours une clé « cmd » — et tuerait la
     * sauvegarde sur une erreur fatale, avant toute écriture, sans rien laisser
     * dans le journal du plugin.
     *
     * Le troisième argument est ce qui distingue ce plugin de l'intégration
     * Home Assistant : sans lui, checkAndUpdateCmd() remet lastCommunication à
     * maintenant même quand la valeur n'a pas changé, et republier une donnée
     * de trois jours rajeunirait l'équipement — qui serait déclaré vivant pour
     * toujours, exactement le défaut qu'on veut éviter.
     */
    private function publishCmd($_logicalId, $_value, $_when = null) {
        if ($_value === null || $_value === '') {
            /* Ne jamais remplacer une valeur connue par du vide : une lecture
             * en échec ne doit pas effacer ce que l'on savait. */
            $cmd = $this->getCmd(null, $_logicalId);
            if (is_object($cmd) && $cmd->execCmd() !== '' && $cmd->execCmd() !== null) {
                return;
            }
        }
        $this->checkAndUpdateCmd($_logicalId, $_value, $_when);
    }

    /* ================================================================= HTTP */

    /*
     * La clé change à minuit et se calcule sur la date locale. Jamais mise en
     * cache : le premier appel après minuit échouerait.
     */
    public static function apiKey($_service) {
        $date = new DateTime('now', new DateTimeZone(self::timezone()));
        return md5(self::API_SECRET . ';' . $_service . ';' . $date->format('d/m/Y'));
    }

    public static function call($_service, $_params = array()) {
        $params = array_merge(array(
            's' => $_service,
            'k' => self::apiKey($_service),
            'l' => self::language(),
        ), $_params);

        $url = self::API_BASE . '?' . http_build_query($params);
        $code = 0;
        $body = self::httpGet($url, $code);

        if ($body === false) {
            throw new Exception(__('L\'IRM ne répond pas.', __FILE__));
        }
        if ($code == 400) {
            throw new Exception(__('L\'IRM a refusé la demande : commune inconnue ou clé expirée.', __FILE__));
        }
        if ($code < 200 || $code >= 300) {
            throw new Exception(__('L\'IRM a refusé la demande :', __FILE__) . ' HTTP ' . $code);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new Exception(__('Réponse illisible de l\'IRM.', __FILE__));
        }
        return $data;
    }

    private static function httpGet($_url, &$_code = null) {
        $timeout = max(3, (int) config::byKey('api_timeout', __CLASS__, 10));

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL            => $_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_ENCODING       => '',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_HTTPHEADER     => array('Accept: application/json'),
            /* Un service gratuit et non documenté mérite au moins qu'on se
             * nomme : l'IRM peut ainsi voir qui l'appelle et nous joindre. */
            CURLOPT_USERAGENT      => 'JeedomMeteoBelgiqueIRM/' . self::pluginVersion()
                                    . ' (+https://github.com/replicatorbe/jeedom-plugin-meteobelgiqueirm)',
        ));
        $body = curl_exec($curl);
        $_code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            log::add(__CLASS__, 'debug', __('Requête en échec :', __FILE__) . ' ' . $_url . ' (' . $error . ')');
            return false;
        }
        return $body;
    }

    /* =============================================================== MODÈLE */

    /*
     * Transforme la réponse brute en un modèle stable, indexé par date. C'est le
     * seul endroit qui connaît la forme de l'API : tout le reste du plugin
     * travaille sur ce modèle, ce qui limite les dégâts à la prochaine rupture.
     */
    private function buildModel($_raw) {
        $city = isset($_raw['cityName']) ? (string) $_raw['cityName'] : '';
        $country = isset($_raw['country']) ? (string) $_raw['country'] : '';

        /*
         * Hors Belgique, l'API ne dit pas non : elle répond 200 avec la météo de
         * Bruxelles et ne le signale que par le nom de ville. Sans ce contrôle,
         * une commune mal saisie afficherait la météo de Bruxelles pour toujours
         * sans un mot.
         */
        foreach (array('Hors de ', 'Outside the ', 'Buiten de ', 'außerhalb der ') as $sentinel) {
            if (strpos($city, $sentinel) === 0) {
                throw new Exception(__('Commune hors de Belgique : l\'IRM a répondu par la météo de Bruxelles.', __FILE__));
            }
        }
        if ($country !== '' && $country !== 'BE') {
            throw new Exception(sprintf(__('Commune située en dehors de la Belgique (%s).', __FILE__), $country));
        }

        $model = array(
            'fetched_at' => time(),
            'city'       => self::sanitizeText($city),
            'country'    => $country,
            'obs'        => array(),
            'current'    => array(),
            'uv'         => null,
            'days'       => array(),
            'hourly'     => array(),
            'warnings'   => array(),
            'nowcast'    => array(),
        );

        /* --- Observation --- */
        if (isset($_raw['obs']) && is_array($_raw['obs'])) {
            $o = $_raw['obs'];
            $model['obs'] = array(
                'temp' => self::fnum(isset($o['temp']) ? $o['temp'] : null),
                'ww'   => self::fint(isset($o['ww']) ? $o['ww'] : null),
                'time' => isset($o['timestamp']) ? substr((string) $o['timestamp'], 11, 5) : '',
                /* Le bloc d'observation n'a pas toujours la période : on
                 * retombe alors sur l'échéance horaire courante. */
                'day_night' => isset($o['dayNight']) ? (string) $o['dayNight'] : '',
            );
        }

        $model['hourly'] = $this->parseHourly($_raw);
        $model['days'] = $this->parseDaily($_raw);
        $model['warnings'] = $this->parseWarnings($_raw);
        $model['nowcast'] = $this->parseNowcast($_raw);

        /* L'observation ne porte ni vent, ni pression : ils viennent de
         * l'échéance horaire qui couvre l'instant présent. */
        $now = time();
        foreach ($model['hourly'] as $h) {
            if ($h['ts'] <= $now) {
                $model['current'] = $h;
            }
        }
        if (empty($model['current']) && !empty($model['hourly'])) {
            $model['current'] = $model['hourly'][0];
        }
        if ($model['obs']['day_night'] === '' && isset($model['current']['day_night'])) {
            $model['obs']['day_night'] = $model['current']['day_night'];
        }
        if ($model['obs']['day_night'] === '') {
            $model['obs']['day_night'] = 'd';
        }

        /* --- Indice UV, quand il est là : le module est absent au Luxembourg
         * et a déjà disparu quelques semaines côté IRM. --- */
        if (isset($_raw['module']) && is_array($_raw['module'])) {
            foreach ($_raw['module'] as $m) {
                if (isset($m['type']) && $m['type'] === 'uv' && isset($m['data']['levelValue'])) {
                    $model['uv'] = self::fnum($m['data']['levelValue']);
                }
            }
        }

        return $model;
    }

    /*
     * Les échéances horaires ne portent aucune date : seule l'entrée de minuit
     * porte un « dateShow ». Entre 00 h et 01 h, cette entrée est la première de
     * la liste — incrémenter le jour en la voyant décalerait toutes les
     * prévisions d'un jour, une heure par nuit. Le « $i > 0 » est tout le
     * correctif, et c'est exactement le bug qu'a connu l'intégration Home
     * Assistant en mai 2024.
     */
    private function parseHourly($_raw) {
        $out = array();
        if (!isset($_raw['for']['hourly']) || !is_array($_raw['for']['hourly'])) {
            return $out;
        }

        $tz = new DateTimeZone(self::timezone());
        $day = new DateTime('today', $tz);
        $previous = null;

        foreach ($_raw['for']['hourly'] as $i => $f) {
            if (!isset($f['hour'])) {
                continue;
            }
            if ($i > 0 && isset($f['dateShow']) && $f['dateShow'] !== null && $f['dateShow'] !== '') {
                $day->modify('+1 day');
            }
            $hour = (int) $f['hour'];

            /*
             * La nuit du changement d'heure compte 23 ou 25 heures, et l'API
             * peut alors répéter une heure. Si l'heure lue recule sans qu'un
             * changement de date ait été annoncé, c'est qu'on a franchi minuit
             * sans « dateShow » : on recale.
             */
            if ($previous !== null && $hour < $previous && !(isset($f['dateShow']) && $f['dateShow'])) {
                $day->modify('+1 day');
            }
            $previous = $hour;

            $day->setTime($hour, 0, 0);

            $out[] = array(
                'ts'                  => $day->getTimestamp(),
                'hour'                => $hour,
                'temp'                => self::fnum(isset($f['temp']) ? $f['temp'] : null),
                'ww'                  => self::fint(isset($f['ww']) ? $f['ww'] : null),
                'day_night'           => isset($f['dayNight']) ? (string) $f['dayNight'] : 'd',
                'rain_chance'         => self::fnum(isset($f['precipChance']) ? $f['precipChance'] : null),
                'rain_qty'            => self::fnum(isset($f['precipQuantity']) ? $f['precipQuantity'] : null),
                'pressure'            => self::fnum(isset($f['pressure']) ? $f['pressure'] : null),
                'wind_speed'          => self::fnum(isset($f['windSpeedKm']) ? $f['windSpeedKm'] : null),
                'wind_gust'           => self::fnum(isset($f['windPeakSpeedKm']) ? $f['windPeakSpeedKm'] : null),
                'wind_direction'      => self::windDirection(isset($f['windDirection']) ? $f['windDirection'] : null,
                                                             isset($f['windDirectionText']) ? $f['windDirectionText'] : null),
                'wind_direction_text' => self::pickLang(isset($f['windDirectionText']) ? $f['windDirectionText'] : null),
            );
        }
        return $out;
    }

    /*
     * Le tableau journalier ne commence pas à aujourd'hui : le soir, sa première
     * entrée est « Cette nuit », avec une température maximale nulle. Sa
     * composition dépend donc de l'heure d'appel, et l'indexer par position
     * viderait la température maximale du jour tous les soirs. On l'indexe par
     * date, reconstruite à partir du nom de jour anglais — seule clé stable,
     * quelle que soit la langue demandée.
     */
    private function parseDaily($_raw) {
        $out = array();
        if (!isset($_raw['for']['daily']) || !is_array($_raw['for']['daily'])) {
            return $out;
        }

        $tz = new DateTimeZone(self::timezone());
        $cursor = new DateTime('now', $tz);
        $weekdays = array('Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4,
                          'Friday' => 5, 'Saturday' => 6, 'Sunday' => 7);

        foreach ($_raw['for']['daily'] as $f) {
            $nameEn = isset($f['dayName']['en']) ? trim((string) $f['dayName']['en']) : '';

            /*
             * L'IRM glisse parfois des annonces de service dans la liste des
             * prévisions (« End of support » pour les communes néerlandaises).
             * Tout ce qui n'est pas un jour reconnu est écarté.
             */
            $isToday = ($nameEn === 'Today' || $nameEn === 'Tonight');
            if (!$isToday && $nameEn !== 'Tomorrow' && !isset($weekdays[$nameEn])) {
                continue;
            }

            if ($isToday) {
                $cursor = new DateTime('now', $tz);
            } elseif ($nameEn === 'Tomorrow') {
                $cursor = new DateTime('tomorrow', $tz);
            } else {
                $delta = $weekdays[$nameEn] - (int) $cursor->format('N');
                if ($delta < 0) { $delta += 7; }
                if ($delta > 0) { $cursor->modify('+' . $delta . ' day'); }
            }
            $date = $cursor->format('Y-m-d');

            $tmin = self::fnum(isset($f['tempMin']) ? $f['tempMin'] : null);
            $tmax = self::fnum(isset($f['tempMax']) ? $f['tempMax'] : null);
            /* L'API renseigne min et max selon la période couverte, sans garantir
             * l'ordre : un minimum supérieur au maximum arrive. */
            if ($tmin !== null && $tmax !== null && $tmin > $tmax) {
                $swap = $tmin; $tmin = $tmax; $tmax = $swap;
            }

            $isNight = (isset($f['dayNight']) && $f['dayNight'] === 'n');

            /*
             * Plusieurs entrées peuvent tomber sur la même date (le bloc nuit et
             * le bloc jour). On fusionne sans jamais écraser une valeur connue
             * par une valeur nulle : le soir, le bloc « Cette nuit » ne doit pas
             * effacer la température maximale déjà lue pour la journée.
             */
            if (!isset($out[$date])) {
                $out[$date] = array(
                    'date' => $date, 'tmin' => null, 'tmax' => null, 'ww' => null,
                    'rain_chance' => null, 'text' => '', 'sunrise' => null, 'sunset' => null,
                );
            }
            if ($tmin !== null) { $out[$date]['tmin'] = $tmin; }
            if ($tmax !== null) { $out[$date]['tmax'] = $tmax; }

            $ww = self::fint(isset($f['ww1']) ? $f['ww1'] : null);
            if ($ww !== null && (!$isNight || $out[$date]['ww'] === null)) {
                $out[$date]['ww'] = $ww;
            }

            $chance = self::fnum(isset($f['precipChance']) ? $f['precipChance'] : null);
            if ($chance !== null) { $out[$date]['rain_chance'] = $chance; }

            $text = self::pickLang(isset($f['text']) ? $f['text'] : null);
            if ($text !== '' && $out[$date]['text'] === '') {
                $out[$date]['text'] = self::sanitizeText($text);
            }

            /* Secondes depuis minuit local, publiées en entier HMM. */
            $rise = self::fint(isset($f['dawnRiseSeconds']) ? $f['dawnRiseSeconds'] : null);
            $set  = self::fint(isset($f['dawnSetSeconds']) ? $f['dawnSetSeconds'] : null);
            if ($rise !== null && $rise > 0) { $out[$date]['sunrise'] = self::secondsToHmm($rise); }
            if ($set !== null && $set > 0)   { $out[$date]['sunset']  = self::secondsToHmm($set); }
        }

        ksort($out);
        return $out;
    }

    private function parseWarnings($_raw) {
        $out = array();
        if (!isset($_raw['for']['warning']) || !is_array($_raw['for']['warning'])) {
            return $out;
        }

        foreach ($_raw['for']['warning'] as $w) {
            if (!isset($w['warningType']['id'], $w['fromTimestamp'], $w['toTimestamp'])) {
                /* Journalisé plutôt qu'ignoré : un avertissement visible dans
                 * l'application officielle et absent ici doit laisser une trace. */
                log::add(__CLASS__, 'warning', __('Avertissement ignoré, champs manquants.', __FILE__));
                continue;
            }
            try {
                $from = new DateTime($w['fromTimestamp']);
                $to   = new DateTime($w['toTimestamp']);
            } catch (Throwable $e) {
                log::add(__CLASS__, 'warning', __('Avertissement ignoré, dates illisibles.', __FILE__));
                continue;
            }

            $id = (int) $w['warningType']['id'];
            $type = isset(self::WARNING_TYPES[$id])
                ? self::WARNING_TYPES[$id]
                : array('warning_' . $id, __('Avertissement', __FILE__) . ' ' . $id);

            $label = self::pickLang(isset($w['warningType']['name']) ? $w['warningType']['name'] : null);

            $out[] = array(
                'id'    => $id,
                'slug'  => $type[0],
                'name'  => self::sanitizeText($label !== '' ? $label : $type[1]),
                'level' => self::fint(isset($w['warningLevel']) ? $w['warningLevel'] : null),
                'text'  => self::sanitizeText(self::pickLang(isset($w['text']) ? $w['text'] : null)),
                'from'  => $from->getTimestamp(),
                'to'    => $to->getTimestamp(),
            );
        }
        return $out;
    }

    /*
     * La séquence radar porte déjà les valeurs chiffrées : aucune image à
     * décoder. Attention à l'unité, qui n'est pas la même partout — mm par
     * tranche de dix minutes en Belgique, mm par heure aux Pays-Bas.
     */
    private function parseNowcast($_raw) {
        $out = array('hint' => '', 'unit' => '', 'per_hour' => 6.0, 'frames' => array());
        if (!isset($_raw['animation']) || !is_array($_raw['animation'])) {
            return $out;
        }
        $a = $_raw['animation'];

        $out['hint'] = self::sanitizeText(self::pickLang(isset($a['sequenceHint']) ? $a['sequenceHint'] : null));
        $out['unit'] = self::pickLang(isset($a['unit']) ? $a['unit'] : null);
        $out['per_hour'] = (strpos($out['unit'], '10min') !== false) ? 6.0 : 1.0;

        if (!isset($a['sequence']) || !is_array($a['sequence'])) {
            return $out;
        }
        foreach ($a['sequence'] as $f) {
            if (!isset($f['time'])) {
                continue;
            }
            $ts = strtotime($f['time']);
            if ($ts === false) {
                continue;
            }
            $value = self::fnum(isset($f['value']) ? $f['value'] : null);
            $out['frames'][] = array(
                'ts'    => $ts,
                'value' => $value === null ? 0.0 : $value,
            );
        }
        return $out;
    }

    /* ================================================== LECTURE DU MODÈLE */

    /* Le jour J+$_offset, ou un tableau vide s'il n'est pas dans la réponse. */
    private function dayAt($_model, $_offset) {
        if (!isset($_model['days']) || !is_array($_model['days'])) {
            return array();
        }
        $tz = new DateTimeZone(self::timezone());
        $d = new DateTime('today', $tz);
        if ($_offset > 0) {
            $d->modify('+' . (int) $_offset . ' day');
        }
        $key = $d->format('Y-m-d');
        return isset($_model['days'][$key]) ? $_model['days'][$key] : array();
    }

    /*
     * État de la pluie à l'instant donné. Le décompte est recalculé à chaque
     * passage du cron, sans nouvel appel : c'est ce qui fait qu'il s'épuise.
     */
    private function nowcastAt($_model, $_now) {
        $out = array('now' => null, 'next' => self::UNKNOWN, 'soon' => 0, 'hint' => '');
        if (!isset($_model['nowcast']['frames']) || !is_array($_model['nowcast']['frames'])) {
            return $out;
        }
        $n = $_model['nowcast'];
        $out['hint'] = isset($n['hint']) ? $n['hint'] : '';
        $perHour = isset($n['per_hour']) ? (float) $n['per_hour'] : 6.0;

        $current = null;
        foreach ($n['frames'] as $f) {
            if ($f['ts'] <= $_now) {
                $current = $f;
            }
        }
        if ($current !== null) {
            $out['now'] = round($current['value'] * $perHour, 2);
        }

        foreach ($n['frames'] as $f) {
            if ($f['ts'] > $_now && $f['value'] > 0) {
                $out['next'] = (int) round(($f['ts'] - $_now) / 60);
                $out['soon'] = 1;
                break;
            }
        }
        return $out;
    }

    /*
     * Les avertissements sont publiés à l'avance : un avertissement présent dans
     * la réponse n'est pas forcément en cours. Sans cette distinction, les
     * volets se fermeraient douze heures trop tôt. Le calcul est refait à chaque
     * passage du cron, pas seulement à chaque lecture réseau, sinon une alerte
     * qui commence à 14 h 00 deviendrait active avec dix minutes de retard.
     */
    private function warningsAt($_model, $_now) {
        $out = array(
            'active' => 0, 'level' => 0, 'slug' => '', 'slugs' => '', 'label' => '',
            'text' => '', 'end' => '', 'count' => 0,
            'next_level' => 0, 'next_slug' => '', 'next_start' => '',
        );

        /* Sans lecture réussie, on ne sait rien — et « rien » n'est pas
         * « aucun avertissement ». */
        if (empty($_model['fetched_at'])) {
            $out['level'] = self::UNKNOWN;
            $out['next_level'] = self::UNKNOWN;
            return $out;
        }
        if (!isset($_model['warnings']) || !is_array($_model['warnings'])) {
            return $out;
        }

        $active = array();
        $upcoming = array();
        foreach ($_model['warnings'] as $w) {
            if ($w['from'] <= $_now && $_now < $w['to']) {
                $active[] = $w;
            } elseif ($_now < $w['from']) {
                $upcoming[] = $w;
            }
        }

        $out['count'] = count($active);

        if (!empty($active)) {
            $worst = $active[0];
            $slugs = array();
            foreach ($active as $w) {
                $slugs[] = $w['slug'];
                if ((int) $w['level'] > (int) $worst['level']) {
                    $worst = $w;
                }
            }
            /*
             * Tri alphabétique obligatoire : si l'IRM rend « vent, pluie » puis
             * « pluie, vent » d'un appel à l'autre, la commande basculerait à
             * chaque passage et réveillerait les scénarios en boucle.
             */
            sort($slugs, SORT_STRING);
            $slugs = array_values(array_unique($slugs));

            $level = (int) $worst['level'];
            $out['active'] = 1;
            $out['level'] = $level;
            $out['slug'] = $worst['slug'];
            $out['slugs'] = implode(',', $slugs);
            $out['label'] = (isset(self::WARNING_LEVELS[$level]) ? __(self::WARNING_LEVELS[$level], __FILE__) : '')
                          . ' — ' . $worst['name'];
            $out['text'] = $worst['text'];
            /*
             * Instant absolu, jamais une durée relative : « fin dans 3 h »
             * changerait à chaque passage du cron et redéclencherait tous les
             * scénarios toutes les dix minutes. Le « dans 3 h » se calcule dans
             * le gabarit, côté navigateur.
             */
            $out['end'] = date('Y-m-d H:i', $worst['to']);
        }

        if (!empty($upcoming)) {
            $next = $upcoming[0];
            foreach ($upcoming as $w) {
                if ($w['from'] < $next['from']) {
                    $next = $w;
                }
            }
            $out['next_level'] = (int) $next['level'];
            $out['next_slug'] = $next['slug'];
            $out['next_start'] = date('Y-m-d H:i', $next['from']);
        }

        return $out;
    }

    /*
     * Charge utile de la tuile. Un seul objet JSON plutôt que plusieurs
     * commandes : deux commandes distinctes demanderaient deux rafraîchissements
     * qui pourraient se croiser et afficher une température avec l'icône de la
     * veille.
     *
     * event::adds tronque la valeur à 3096 caractères : le bulletin rédigé est
     * coupé, et seuls trois jours sont embarqués.
     */
    private function buildResume($_model, $_warning, $_nowcast, $_ww, $_now) {
        $obs = isset($_model['obs']) ? $_model['obs'] : array();
        $today = $this->dayAt($_model, 0);
        $fetchedAt = isset($_model['fetched_at']) ? (int) $_model['fetched_at'] : 0;

        $days = array();
        for ($i = 1; $i <= 3; $i++) {
            $d = $this->dayAt($_model, $i);
            if (empty($d)) {
                continue;
            }
            $dw = self::describeWw(isset($d['ww']) ? $d['ww'] : null, 'd');
            $ts = strtotime($d['date']);
            $days[] = array(
                'label' => self::dayLabel($ts),
                'icon'  => $dw[2],
                'tmin'  => isset($d['tmin']) ? $d['tmin'] : null,
                'tmax'  => isset($d['tmax']) ? $d['tmax'] : null,
            );
        }

        $payload = array(
            'city'      => isset($_model['city']) ? $_model['city'] : '',
            'temp'      => isset($obs['temp']) ? $obs['temp'] : null,
            'condition' => $_ww[0],
            'icon'      => $_ww[2],
            'tmin'      => isset($today['tmin']) ? $today['tmin'] : null,
            'tmax'      => isset($today['tmax']) ? $today['tmax'] : null,
            'rain'      => array(
                'now'  => $_nowcast['now'],
                'next' => $_nowcast['next'],
                'hint' => $_nowcast['hint'],
            ),
            'warning'   => array(
                'level' => $_warning['level'],
                'label' => $_warning['label'],
                'end'   => $_warning['end'],
            ),
            'next_warning' => array(
                'level' => $_warning['next_level'],
                'slug'  => $_warning['next_slug'],
                'start' => $_warning['next_start'],
            ),
            'days'      => $days,
            'hours'     => $this->buildHours($_model, $_now),
            /* Le gabarit calcule l'âge lui-même à partir de cet instant : une
             * durée figée ici vieillirait mal entre deux passages du cron. */
            'fetched'   => $fetchedAt,
            'stale'     => ($fetchedAt > 0 && ($_now - $fetchedAt) > 3600) ? 1 : 0,
        );

        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    /*
     * Les heures à venir, pour la bande de la tuile. « Va-t-il pleuvoir cet
     * après-midi » est la question qu'on pose le plus souvent à un dashboard, et
     * elle ne se lit ni sur la température du moment ni sur un maximum
     * journalier.
     */
    private function buildHours($_model, $_now) {
        $all = isset($_model['hourly']) ? $_model['hourly'] : array();
        if (empty($all)) {
            return array();
        }

        $today = date('Y-m-d', $_now);
        $rest = array();
        $future = array();

        foreach ($all as $h) {
            /* L'heure en cours est conservée : à 14 h 50, la colonne « 14 h »
             * décrit encore le temps qu'il fait. */
            if ($h['ts'] + 3600 <= $_now) {
                continue;
            }
            $future[] = $h;
            if (date('Y-m-d', $h['ts']) === $today) {
                $rest[] = $h;
            }
        }

        $picked = (count($rest) >= self::HOURS_MIN) ? $rest : $future;
        $picked = array_slice($picked, 0, self::HOURS_SPAN);
        if (empty($picked)) {
            return array();
        }

        /* Une colonne par heure tant que ça tient, sinon on regroupe. */
        $step = (int) ceil(count($picked) / self::HOURS_COLUMNS);
        if ($step < 1) {
            $step = 1;
        }

        $out = array();
        $previousDay = $today;
        for ($i = 0; $i < count($picked); $i += $step) {
            $h = $picked[$i];
            $day = date('Y-m-d', $h['ts']);
            $ww = self::describeWw($h['ww'], isset($h['day_night']) ? $h['day_night'] : 'd');

            /*
             * Le risque de pluie est celui du pire moment de l'intervalle : une
             * averse à 15 h ne doit pas disparaître parce que la colonne porte
             * l'étiquette « 14 h ».
             */
            $risk = null;
            for ($k = $i; $k < min($i + $step, count($picked)); $k++) {
                $r = isset($picked[$k]['rain_chance']) ? $picked[$k]['rain_chance'] : null;
                if ($r !== null && ($risk === null || $r > $risk)) {
                    $risk = $r;
                }
            }

            /* Clés volontairement courtes : décrites au long, seize heures
             * feraient à elles seules la moitié du budget de la commande. */
            $out[] = array(
                'h' => (int) date('G', $h['ts']),
                'i' => $ww[2],
                't' => isset($h['temp']) && $h['temp'] !== null ? (int) round($h['temp']) : null,
                'r' => $risk === null ? null : (int) round($risk),
                'n' => ($day !== $previousDay) ? 1 : 0,
            );
            $previousDay = $day;
        }
        return $out;
    }

    /* ================================================================ CACHE */

    private function forecastKey() {
        return __CLASS__ . '::forecast::' . $this->getId();
    }

    public function getForecast() {
        $cache = cache::byKey($this->forecastKey());
        $value = $cache->getValue();
        return is_array($value) ? $value : array();
    }

    private function saveForecast($_model) {
        cache::set($this->forecastKey(), $_model, self::FORECAST_TTL);
    }

    private function clearForecast() {
        $cache = cache::byKey($this->forecastKey());
        if (is_object($cache)) {
            $cache->remove();
        }
    }

    /*
     * Les clés sont déclarées ici et nulle part ailleurs : un nettoyage qui
     * invente ses propres clés manque sa cible en silence dès que la classe en
     * change. Appelée par install.php à la désactivation du plugin.
     */
    public static function clearCaches() {
        foreach (self::byType(__CLASS__) as $eqLogic) {
            try {
                $eqLogic->clearForecast();
            } catch (Throwable $e) {
                log::add(__CLASS__, 'debug', $e->getMessage());
            }
        }
    }

    /*
     * Le petit magasin par équipement — signature, dernier forçage, compteur
     * d'échecs — utilise getCache() et setCache() d'eqLogic, et surtout ne les
     * redéfinit pas.
     *
     * Les redéfinir en privé a coûté une panne totale : PHP refuse de réduire la
     * visibilité d'une méthode héritée, l'erreur est fatale au chargement de la
     * classe, et le coeur charge la classe de chaque plugin actif sur CHAQUE
     * page. Résultat, toute l'interface de Jeedom en HTTP 500, pas seulement ce
     * plugin. Le symptôme ne ressemble en rien à sa cause : c'est le pendant du
     * piège setCmd(), pour les méthodes plutôt que pour les propriétés.
     */

    /* ================================================================ RECUL */

    /*
     * Un service momentanément indisponible est retrouvé au passage suivant,
     * mais une commune définitivement invalide ne coûte plus que vingt-quatre
     * requêtes par jour au lieu de cent quarante-quatre.
     */
    public function shouldPoll() {
        if (!$this->isConfigured()) {
            return false;
        }
        $model = $this->getForecast();
        $fetchedAt = isset($model['fetched_at']) ? (int) $model['fetched_at'] : 0;

        $failures = (int) $this->getCache('failures', 0);
        if ($failures > 0) {
            $wait = min(self::BACKOFF_MAX, self::BACKOFF_MIN * pow(2, $failures - 1));
            $lastTry = (int) $this->getCache('lastTry', 0);
            if ($lastTry > 0 && (time() - $lastTry) < $wait) {
                return false;
            }
        }
        return true;
    }

    private function noteFailure() {
        $this->setCache('failures', (int) $this->getCache('failures', 0) + 1);
        $this->setCache('lastTry', time());
    }

    private function clearFailure() {
        $this->setCache('failures', 0);
        $this->setCache('lastTry', time());
    }

    /* ============================================================= MESSAGES */

    /*
     * Le journal du plugin n'est pas lu par l'utilisateur ; le centre de
     * messages, si. message::save() ne met à jour que la date et le compteur,
     * jamais le texte : sans le removeAll préalable, la première cause resterait
     * affichée pour toujours.
     */
    private function reportProblem($_text) {
        $text = $this->getHumanName() . ' ' . $_text;
        log::add(__CLASS__, 'warning', $text);
        message::removeAll(__CLASS__, 'commune' . $this->getId());
        message::add(__CLASS__, $text, '', 'commune' . $this->getId());
    }

    private function clearProblem() {
        message::removeAll(__CLASS__, 'commune' . $this->getId());
    }

    public function getRefreshError() {
        return $this->_refreshError;
    }

    /* ============================================================= COMMUNES */

    /*
     * Les 565 communes belges sont livrées avec le plugin plutôt qu'obtenues par
     * le service de recherche de l'IRM. Trois raisons : la recherche est bruyante
     * (« Brugge » remonte aussi Gand, Riemst et Poperinge), elle est plafonnée à
     * trois résultats, et surtout elle ne répondrait pas si l'IRM était
     * indisponible au moment précis où l'on configure le plugin.
     *
     * Les noms néerlandais ne sont stockés que lorsqu'ils diffèrent du français,
     * ce qui permet de chercher « Elsene » comme « Ixelles ».
     */
    public static function communes() {
        $path = __DIR__ . '/../config/communes.json';
        if (!is_readable($path)) {
            return array();
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : array();
    }

    public static function communeName($_ins) {
        $communes = self::communes();
        $ins = (string) $_ins;
        return isset($communes[$ins]['fr']) ? $communes[$ins]['fr'] : '';
    }

    /* Recherche locale, insensible à la casse et aux accents, sur les deux
     * langues. */
    public static function searchCommunes($_query, $_limit = 30) {
        $query = self::normalize($_query);
        $out = array();
        if ($query === '') {
            return $out;
        }

        $exact = array();
        $starts = array();
        $contains = array();

        foreach (self::communes() as $ins => $names) {
            $labels = array($names['fr']);
            if (isset($names['nl'])) {
                $labels[] = $names['nl'];
            }
            foreach ($labels as $label) {
                $norm = self::normalize($label);
                if ($norm === $query) {
                    $exact[$ins] = $names;
                } elseif (strpos($norm, $query) === 0) {
                    $starts[$ins] = $names;
                } elseif (strpos($norm, $query) !== false) {
                    $contains[$ins] = $names;
                }
            }
        }

        foreach (array($exact, $starts, $contains) as $bucket) {
            foreach ($bucket as $ins => $names) {
                if (isset($out[$ins]) || count($out) >= $_limit) {
                    continue;
                }
                $label = $names['fr'];
                if (isset($names['nl'])) {
                    $label .= ' / ' . $names['nl'];
                }
                $out[$ins] = $label;
            }
        }
        return $out;
    }

    /* =============================================================== OUTILS */

    /*
     * Conversion numérique stricte. Indispensable, et pas seulement par
     * élégance : en PHP, (float) null vaut 0.0 sans le moindre avertissement.
     * Un champ que l'IRM cesserait d'envoyer — c'est arrivé en janvier 2026 —
     * deviendrait donc silencieusement 0 °C ou 0 km/h, ce qui est bien pire
     * qu'une erreur franche.
     */
    public static function fnum($_value) {
        if ($_value === null || $_value === '' || is_array($_value) || is_bool($_value)) {
            return null;
        }
        return is_numeric($_value) ? (float) $_value : null;
    }

    public static function fint($_value) {
        $f = self::fnum($_value);
        return $f === null ? null : (int) $f;
    }

    /*
     * La direction n'est pas celle d'où vient le vent : c'est l'angle de
     * rotation d'une flèche qui pointe le nord au repos. Sans la correction, le
     * vent est annoncé à l'exact opposé de sa direction réelle.
     */
    public static function windDirection($_degrees, $_text = null) {
        $d = self::fint($_degrees);
        if ($d === null) {
            return null;
        }
        /* « VAR » : direction variable, elle n'a alors pas de sens. */
        $label = self::pickLang($_text, 'en');
        if (strtoupper(trim((string) $label)) === 'VAR') {
            return null;
        }
        return ($d + 180) % 360;
    }

    /* Entier HMM attendu par le coeur : 732 pour 7 h 32. */
    public static function secondsToHmm($_seconds) {
        $s = (int) $_seconds;
        return (int) (floor($s / 3600) * 100 + floor(($s % 3600) / 60));
    }

    /*
     * Les textes de l'IRM ne sont pas fournis dans toutes les langues : le
     * bulletin rédigé n'existe qu'en français et en néerlandais pour la
     * Belgique. Sans repli, il serait vide pour un Jeedom en anglais.
     */
    public static function pickLang($_field, $_preferred = null) {
        if (!is_array($_field)) {
            return is_string($_field) ? $_field : '';
        }
        $order = array($_preferred === null ? self::language() : $_preferred, 'fr', 'nl', 'en', 'de');
        foreach ($order as $lang) {
            if ($lang !== null && isset($_field[$lang]) && $_field[$lang] !== '') {
                return (string) $_field[$lang];
            }
        }
        foreach ($_field as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return '';
    }

    /*
     * Une valeur de commande finit dans un littéral JavaScript, échappé par
     * addslashes() — qui ne protège pas d'un « </script> ». Les chevrons et les
     * caractères de contrôle sautent donc avant toute publication.
     */
    public static function sanitizeText($_text) {
        $text = str_replace(array('<', '>'), '', (string) $_text);
        $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    public static function normalize($_text) {
        $text = mb_strtolower(trim((string) $_text), 'UTF-8');
        $from = array('à','á','â','ä','ã','å','ç','è','é','ê','ë','ì','í','î','ï',
                      'ñ','ò','ó','ô','ö','õ','ù','ú','û','ü','ý','ÿ');
        $to   = array('a','a','a','a','a','a','c','e','e','e','e','i','i','i','i',
                      'n','o','o','o','o','o','u','u','u','u','y','y');
        $text = str_replace($from, $to, $text);
        return preg_replace('/[^a-z0-9]+/', '', $text);
    }

    /* Libellé court d'un jour : « demain », puis le nom du jour. */
    public static function dayLabel($_timestamp) {
        $tz = new DateTimeZone(self::timezone());
        $day = new DateTime('@' . (int) $_timestamp);
        $day->setTimezone($tz);
        $today = new DateTime('today', $tz);
        $delta = (int) $today->diff(new DateTime($day->format('Y-m-d'), $tz))->format('%r%a');

        if ($delta === 0) { return __('Aujourd\'hui', __FILE__); }
        if ($delta === 1) { return __('Demain', __FILE__); }

        $names = array(1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi',
                       5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche');
        $n = (int) $day->format('N');
        return isset($names[$n]) ? __($names[$n], __FILE__) : '';
    }

    /* Libellé, code WeatherAPI et icône d'un code de condition. */
    public static function describeWw($_ww, $_dayNight = 'd') {
        $unknown = array('', null, 'fas fa-question');
        if ($_ww === null || $_ww === '') {
            return $unknown;
        }
        $ww = (int) $_ww;
        if (!isset(self::WW[$ww])) {
            return $unknown;
        }
        $entry = self::WW[$ww];
        $key = ($_dayNight === 'n' && isset($entry['n'])) ? 'n' : 'd';
        $value = $entry[$key];
        return array(__($value[0], __FILE__), $value[1], $value[2]);
    }

    public static function timezone() {
        $tz = config::byKey('timezone', 'core', 'Europe/Brussels');
        return $tz == '' ? 'Europe/Brussels' : $tz;
    }

    /*
     * Langue des textes de l'IRM. Le réglage du plugin l'emporte, sinon on suit
     * Jeedom. L'API ne connaît que fr, nl, en et de.
     */
    public static function language() {
        $lang = trim((string) config::byKey('lang', __CLASS__, ''));
        if ($lang === '') {
            $lang = substr((string) config::byKey('language', 'core', 'fr_FR'), 0, 2);
        }
        return in_array($lang, array('fr', 'nl', 'en', 'de'), true) ? $lang : 'fr';
    }

    public static function pluginVersion() {
        $path = __DIR__ . '/../../plugin_info/info.json';
        if (!is_readable($path)) {
            return '0';
        }
        $info = json_decode(file_get_contents($path), true);
        return isset($info['pluginVersion']) ? $info['pluginVersion'] : '0';
    }

    /* ========================================== DONNÉES POUR L'INTERFACE */

    /*
     * Alimente les onglets de la page du plugin. Lit le cache et n'appelle
     * jamais l'IRM : ouvrir un onglet ne doit pas consommer le quota de
     * l'utilisateur ni dépendre de sa connexion.
     */
    public function toAjax() {
        $model = $this->getForecast();
        $now = time();

        $days = array();
        if (isset($model['days'])) {
            foreach ($model['days'] as $date => $d) {
                $ww = self::describeWw(isset($d['ww']) ? $d['ww'] : null, 'd');
                $days[] = array(
                    'date'        => $date,
                    'label'       => self::dayLabel(strtotime($date)),
                    'tmin'        => $d['tmin'],
                    'tmax'        => $d['tmax'],
                    'condition'   => $ww[0],
                    'icon'        => $ww[2],
                    'rain_chance' => $d['rain_chance'],
                    'text'        => $d['text'],
                );
            }
        }

        $hourly = array();
        if (isset($model['hourly'])) {
            foreach ($model['hourly'] as $h) {
                $ww = self::describeWw($h['ww'], $h['day_night']);
                $hourly[] = array(
                    'time'        => date('d/m H:i', $h['ts']),
                    'past'        => ($h['ts'] < $now) ? 1 : 0,
                    'temp'        => $h['temp'],
                    'condition'   => $ww[0],
                    'icon'        => $ww[2],
                    'rain_chance' => $h['rain_chance'],
                    'wind_speed'  => $h['wind_speed'],
                );
            }
        }

        $warnings = array();
        if (isset($model['warnings'])) {
            foreach ($model['warnings'] as $w) {
                $level = (int) $w['level'];
                $warnings[] = array(
                    'name'   => $w['name'],
                    'slug'   => $w['slug'],
                    'level'  => $level,
                    'levelLabel' => isset(self::WARNING_LEVELS[$level])
                        ? __(self::WARNING_LEVELS[$level], __FILE__) : '',
                    'text'   => $w['text'],
                    'from'   => date('d/m H:i', $w['from']),
                    'to'     => date('d/m H:i', $w['to']),
                    'active' => ($w['from'] <= $now && $now < $w['to']) ? 1 : 0,
                );
            }
        }

        return array(
            'city'     => isset($model['city']) ? $model['city'] : '',
            'ins'      => $this->signature(),
            'fetched'  => isset($model['fetched_at'])
                ? date('d/m/Y H:i', $model['fetched_at']) : '',
            'age'      => isset($model['fetched_at'])
                ? (int) floor(($now - $model['fetched_at']) / 60) : self::UNKNOWN,
            'days'     => $days,
            'hourly'   => $hourly,
            'warnings' => $warnings,
            'nowcast'  => isset($model['nowcast']) ? $model['nowcast'] : array(),
        );
    }
}

class meteobelgiqueirmCmd extends cmd {

    /*
     * Aucune logique métier ici : la commande délègue à l'équipement. Et elle
     * relaie l'échec, sinon un scénario qui appelle « Rafraîchir » croirait sa
     * météo relue alors que l'IRM est en panne.
     */
    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();

        switch ($this->getLogicalId()) {
            case 'refresh':
                $eqLogic->update(true);
                if ($eqLogic->getRefreshError() != '') {
                    throw new Exception($eqLogic->getRefreshError());
                }
                return true;
        }
        return true;
    }
}
