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

require_once __DIR__ . '/../../../core/php/core.inc.php';

function meteobelgiqueirm_install() {
}

/*
 * Une mise à jour qui ajoute des commandes ne les fait pas apparaître toute
 * seule sur les communes déjà créées : createCommands() ne tourne qu'au
 * postSave d'un équipement.
 *
 * Mais on ne peut pas se contenter d'un save() par commune. Le coeur exécute
 * cette fonction DANS la requête HTTP et sans la détacher (plugin::launch()
 * lance le sous-processus sans « & » pour les fonctions d'installation) : avec
 * un postSave qui interroge l'IRM, dix communes et un réseau coupé, la page
 * « Gestion des plugins » partirait en timeout. Pire, postSave écrirait des
 * valeurs de repli dans les commandes info, et les scénarios de l'utilisateur
 * se déclencheraient sur une panne réseau au lieu d'une vraie donnée.
 *
 * rebuildCommands() ne fait donc que la partie hors ligne : créer ce qui
 * manque. Les valeurs, elles, viendront du prochain cron.
 */
function meteobelgiqueirm_update() {
    /*
     * Sans la classe, eqLogic::byType() retombe silencieusement sur la classe
     * de base : le save() réussirait sans créer la moindre commande, et la
     * mise à jour se déclarerait terminée. Mieux vaut le dire.
     */
    if (!class_exists('meteobelgiqueirm')) {
        log::add('meteobelgiqueirm', 'error', __('Classe du plugin introuvable : les commandes n\'ont pas été mises à jour.', __FILE__));
        return;
    }
    try {
        $count = meteobelgiqueirm::rebuildCommands();
        log::add('meteobelgiqueirm', 'info', sprintf(__('Mise à jour : %d commune(s) revue(s).', __FILE__), $count));
    } catch (Throwable $e) {
        log::add('meteobelgiqueirm', 'error', __('Mise à jour des commandes :', __FILE__) . ' ' . $e->getMessage());
    }
}

/*
 * Attention : le coeur appelle cette fonction à la DÉSACTIVATION du plugin
 * (plugin::setIsEnable(0)), pas seulement à sa désinstallation. La liste des
 * 565 communes belges, livrée avec le plugin, n'est pas concernée ; seules les
 * prévisions, périssables par nature, sont jetées.
 *
 * Les clés sont déclarées dans la classe et non ici : un nettoyage qui invente
 * ses propres clés manque sa cible en silence dès que la classe en change.
 */
function meteobelgiqueirm_remove() {
    if (!class_exists('meteobelgiqueirm')) {
        return;
    }
    try {
        meteobelgiqueirm::clearCaches();
    } catch (Throwable $e) {
        log::add('meteobelgiqueirm', 'debug', __('Nettoyage du cache impossible :', __FILE__) . ' ' . $e->getMessage());
    }
}
