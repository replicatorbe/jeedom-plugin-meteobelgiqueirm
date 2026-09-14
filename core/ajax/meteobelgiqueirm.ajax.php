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

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    /* isConnect('admin') est une égalité stricte de profil, pas une hiérarchie :
     * isConnect('user') serait faux pour un administrateur. */
    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    /* Depuis la 4.4, ajax::getToken() est déprécié et rend une chaîne vide :
     * contrôler un jeton ici casserait l'appel. L'authentification repose sur la
     * session et sur credentials: same-origin. */
    ajax::init();

    /*
     * Recherche dans la liste des communes livrée avec le plugin. Aucun appel
     * réseau : la configuration doit rester possible même si l'IRM est
     * indisponible au moment précis où l'utilisateur crée son équipement.
     */
    if (init('action') == 'searchCommunes') {
        ajax::success(meteobelgiqueirm::searchCommunes(init('query')));
    }

    /*
     * Alimente les onglets Prévisions et Avertissements. Lit le cache et
     * n'appelle jamais l'IRM : ouvrir un onglet ne doit ni consommer le quota
     * de l'utilisateur, ni dépendre de sa connexion.
     */
    if (init('action') == 'data') {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'meteobelgiqueirm') {
            throw new Exception(__('Commune introuvable :', __FILE__) . ' ' . init('id'));
        }
        ajax::success($eqLogic->toAjax());
    }

    /* Relecture à la demande, depuis le bouton de la page. */
    if (init('action') == 'refresh') {
        /* Une action qui sort de la box n'a rien à faire dans une démonstration
         * publique. */
        unautorizedInDemo();

        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'meteobelgiqueirm') {
            throw new Exception(__('Commune introuvable :', __FILE__) . ' ' . init('id'));
        }
        $eqLogic->update(true);
        if ($eqLogic->getRefreshError() != '') {
            throw new Exception($eqLogic->getRefreshError());
        }
        ajax::success($eqLogic->toAjax());
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));

/*
 * Throwable et non Exception : en PHP 8, une Error n'hérite pas d'Exception et
 * produirait un HTTP 500 sans corps JSON. Le JS du coeur réessaierait alors
 * trois fois avant d'afficher une erreur réseau incompréhensible.
 */
} catch (Throwable $e) {
    ajax::error(displayException($e), $e->getCode());
}
