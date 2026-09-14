<?php
/* Remplaçants minimaux du coeur de Jeedom, pour rejouer la classe du plugin
 * hors d'une installation. Ils ne simulent que ce dont le code de lecture a
 * besoin : traduction, configuration, journal, et les deux classes de base.
 *
 * Le but n'est pas de tester Jeedom, mais de pouvoir rejouer une réponse de
 * l'IRM capturée un jour de panne et vérifier que le plugin en tire ce qu'il
 * faut. L'intégration Home Assistant garde une fixture par incident ; c'est ce
 * qui lui permet de supporter plusieurs schémas à la fois. */

/* Le coeur de Jeedom pose le fuseau dès la ligne 18 de core.inc.php ; PHP en
 * ligne de commande est en UTC. Sans cela, une échéance de minuit à Bruxelles
 * se relit la veille à 22 h, et les tests de date échouent pour une raison qui
 * n'a rien à voir avec le plugin. */
date_default_timezone_set('Europe/Brussels');

function __($_text, $_file = null) { return $_text; }

class config {
    public static $values = array();
    public static function byKey($_key, $_plugin = 'core', $_default = '') {
        $k = $_plugin . '::' . $_key;
        return isset(self::$values[$k]) ? self::$values[$k] : $_default;
    }
}

class log {
    public static $lines = array();
    public static function add($_plugin, $_level, $_message, $_logicalId = '') {
        self::$lines[] = $_level . ' : ' . $_message;
    }
}

class cacheItem {
    public $value = null;
    public function getValue($_default = null) { return $this->value === null ? $_default : $this->value; }
    public function remove() { $this->value = null; }
}

class cache {
    public static $store = array();
    public static function byKey($_key) {
        if (!isset(self::$store[$_key])) { self::$store[$_key] = new cacheItem(); }
        return self::$store[$_key];
    }
    public static function set($_key, $_value, $_lifetime = 0) {
        self::byKey($_key)->value = $_value;
    }
}

class message {
    public static function add($_plugin, $_message, $_action = '', $_logicalId = '') {}
    public static function removeAll($_plugin, $_logicalId = '') {}
}

class cmd {
    public $logicalId = '';
    public $value = '';
    public $id = 0;
    /* Journal des actions déclenchées : c'est lui qu'on interroge pour vérifier
     * qu'une même alerte ne part pas deux fois. */
    public static $sent = array();
    public function getLogicalId() { return $this->logicalId; }
    public function execCmd($_options = array()) {
        if (!empty($_options)) {
            self::$sent[] = $_options;
            return true;
        }
        return $this->value;
    }
    public static function byId($_id) {
        $c = new self();
        $c->id = $_id;
        return $c;
    }
    public static function byEqLogicIdCmdName($_id, $_name) { return null; }
}

class eqLogic {
    public $published = array();
    public $configuration = array();
    public function getId() { return 1; }
    public function getHumanName() { return '[Test][Commune]'; }
    public function getConfiguration($_key, $_default = '') {
        return isset($this->configuration[$_key]) ? $this->configuration[$_key] : $_default;
    }
    public function setConfiguration($_key, $_value) {
        $this->configuration[$_key] = $_value;
        return $this;
    }
    public function getCmd($_type, $_logicalId) { return null; }
    public function getName() { return 'Commune'; }
    public function checkAndUpdateCmd($_logicalId, $_value, $_when = null) {
        $this->published[$_logicalId] = $_value;
    }
    /* Le plugin n'a pas le droit de redéfinir ces deux-là : elles sont
     * publiques dans eqLogic, et les réduire en privé casse le chargement de
     * la classe — donc tout Jeedom. Le stub les fournit à l'identique. */
    public $store = array();
    public function getCache($_key = '', $_default = '') {
        return isset($this->store[$_key]) ? $this->store[$_key] : $_default;
    }
    public function setCache($_key, $_value = null) {
        $this->store[$_key] = $_value;
    }

    public function refreshWidget() {}
    public function getTimeout() { return 0; }
    public function setTimeout($_v) {}
    public function setIsEnable($_v) {}
    public function setIsVisible($_v) {}
    public function setDisplay($_k, $_v) {}
    public static function byType($_type, $_onlyEnable = false) { return array(); }
}
