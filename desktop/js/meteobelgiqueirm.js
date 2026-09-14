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

/* ================================================================== OUTILS */

function meteobelgiqueirmEl(_id) {
  return document.getElementById(_id)
}

/* Les valeurs venues de l'IRM ne sont jamais écrites en innerHTML : une
   description d'avertissement est du texte, pas du balisage. */
function meteobelgiqueirmCell(_text, _className) {
  var td = document.createElement('td')
  td.textContent = (_text === null || _text === undefined) ? '' : String(_text)
  if (_className) { td.className = _className }
  return td
}

function meteobelgiqueirmNum(_value, _suffix) {
  if (_value === null || _value === undefined || _value === '') { return '-' }
  return String(_value) + (_suffix || '')
}

function meteobelgiqueirmAjax(_action, _data, _success) {
  var payload = { action: _action }
  for (var key in _data) {
    if (Object.prototype.hasOwnProperty.call(_data, key)) { payload[key] = _data[key] }
  }
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/meteobelgiqueirm/core/ajax/meteobelgiqueirm.ajax.php',
    data: payload,
    dataType: 'json',
    /* Le callback d'erreur de domUtils.ajax ne reçoit qu'un seul argument,
       contrairement à celui de jQuery. */
    error: function (error) {
      domUtils.handleAjaxError(error)
    },
    success: _success
  })
}

function meteobelgiqueirmStatus(_text, _level) {
  var span = meteobelgiqueirmEl('span_meteobelgiqueirmStatus')
  if (span === null) { return }
  span.textContent = _text
  span.className = _level ? 'label label-' + _level : ''
}

/* ======================================================= CHOIX DE LA COMMUNE */

/* Rappelle à l'écran la commune enregistrée. Le code INS est affiché à côté du
   nom : deux communes belges portent des noms confondables — Sint-Niklaas et
   Saint-Nicolas remontent toutes deux sur « Saint-Nicolas » — et seul le code
   permet de les distinguer d'un coup d'oeil. */
function meteobelgiqueirmShowCommune() {
  var span = meteobelgiqueirmEl('span_meteobelgiqueirmCommune')
  if (span === null) { return }

  var ins = document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="ins"]')
  var label = document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="ins_label"]')
  var insValue = (ins === null) ? '' : ins.value
  var labelValue = (label === null) ? '' : label.value

  if (insValue === '') {
    span.textContent = '{{Aucune}}'
    span.className = 'label label-warning'
    return
  }
  span.textContent = (labelValue === '' ? '{{Commune}}' : labelValue) + ' — INS ' + insValue
  span.className = 'label label-success'
}

function meteobelgiqueirmResetSearch() {
  var select = meteobelgiqueirmEl('sel_meteobelgiqueirmCommune')
  if (select !== null) { select.innerHTML = '' }
  var input = meteobelgiqueirmEl('in_meteobelgiqueirmSearch')
  if (input !== null) { input.value = '' }
}

function meteobelgiqueirmSearch() {
  var input = meteobelgiqueirmEl('in_meteobelgiqueirmSearch')
  if (input === null) { return }

  var query = input.value.trim()
  if (query.length < 2) {
    jeedomUtils.showAlert({ message: '{{Saisissez au moins deux lettres du nom de la commune.}}', level: 'warning' })
    return
  }

  meteobelgiqueirmAjax('searchCommunes', { query: query }, function (result) {
    var select = meteobelgiqueirmEl('sel_meteobelgiqueirmCommune')
    if (select === null) { return }
    select.innerHTML = ''

    var count = 0
    for (var ins in result.result) {
      if (Object.prototype.hasOwnProperty.call(result.result, ins)) { count++ }
    }

    /* Une recherche sans résultat n'efface jamais le choix enregistré : une
       faute de frappe ne doit pas faire perdre la commune configurée. */
    if (count === 0) {
      jeedomUtils.showAlert({ message: '{{Aucune commune ne correspond. Votre choix actuel est conservé.}}', level: 'warning' })
      return
    }

    /* Première option vide et volontairement neutre : sans elle, le navigateur
       présélectionne la première commune sans émettre d'événement « change », et
       le clic de l'utilisateur sur cette commune-là ne serait jamais enregistré. */
    var empty = document.createElement('option')
    empty.value = ''
    empty.textContent = '{{Choisissez votre commune…}}'
    select.appendChild(empty)

    for (var code in result.result) {
      if (!Object.prototype.hasOwnProperty.call(result.result, code)) { continue }
      var option = document.createElement('option')
      option.value = code
      option.textContent = result.result[code] + ' (' + code + ')'
      select.appendChild(option)
    }

    /* Même avec un seul résultat, on ne valide pas d'office : la recherche peut
       rendre un homonyme lointain, et un choix implicite serait invisible. */
    if (count === 1) {
      jeedomUtils.showAlert({ message: '{{Une commune trouvée : sélectionnez-la dans la liste pour la retenir.}}', level: 'success' })
    }
  })
}

function meteobelgiqueirmPickCommune(_select) {
  if (_select.value === '') { return }

  var ins = document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="ins"]')
  var label = document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="ins_label"]')
  if (ins === null || label === null) { return }

  ins.value = _select.value

  /* Le libellé est débarrassé du code INS ajouté pour l'affichage. On coupe au
     dernier « ( » plutôt que par expression régulière : plusieurs communes
     portent des parenthèses dans leur nom, et l'outil de contrôle de portée du
     dépôt ne sait pas lire un littéral d'expression régulière. */
  var shown = _select.options[_select.selectedIndex].textContent
  var cut = shown.lastIndexOf(' (')
  label.value = (cut > 0) ? shown.substring(0, cut) : shown

  meteobelgiqueirmShowCommune()
  meteobelgiqueirmStatus('{{Commune retenue. Enregistrez pour relever la météo.}}', 'info')
}

/* ================================================================ ALERTES */

/* La liste des actions est stockée dans un seul champ, en identifiants séparés
   par des virgules : un tableau imbriqué dans la configuration se relit mal
   depuis le formulaire du coeur, et se perd au premier enregistrement fait
   depuis un autre écran. */
function meteobelgiqueirmActionField() {
  return document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="alert_cmds"]')
}

function meteobelgiqueirmActionIds() {
  var field = meteobelgiqueirmActionField()
  if (field === null || field.value === '') { return [] }
  /* Un identifiant de commande est un entier : parseInt écarte d'un coup les
     espaces, les dièses que rend le sélecteur du coeur, et les restes d'une
     saisie manuelle. */
  var out = []
  var parts = field.value.split(',')
  for (var i = 0; i < parts.length; i++) {
    var id = parseInt(parts[i].replace('#', ''), 10)
    if (!isNaN(id) && id > 0) { out.push(String(id)) }
  }
  return out
}

function meteobelgiqueirmSetActionIds(_ids) {
  var field = meteobelgiqueirmActionField()
  if (field === null) { return }
  field.value = _ids.join(',')
  meteobelgiqueirmShowActions()
}

/* Affiche chaque action avec son nom complet, et une croix pour la retirer. Un
   identifiant nu ne dit rien à personne trois mois plus tard. */
function meteobelgiqueirmShowActions() {
  var box = meteobelgiqueirmEl('div_meteobelgiqueirmActions')
  if (box === null) { return }
  box.innerHTML = ''

  var ids = meteobelgiqueirmActionIds()
  if (ids.length === 0) {
    var none = document.createElement('div')
    none.className = 'help-block'
    none.style.margin = '0 0 5px 0'
    none.textContent = '{{Aucune action : vous ne serez prévenu de rien.}}'
    box.appendChild(none)
    return
  }

  for (var i = 0; i < ids.length; i++) {
    var row = document.createElement('div')
    row.style.marginBottom = '3px'

    var tag = document.createElement('span')
    tag.className = 'label label-info'
    tag.style.fontSize = '1em'
    tag.style.marginRight = '5px'
    /* jeedom.cmd.byId est asynchrone : on affiche l'identifiant d'abord, le nom
       le remplace dès qu'il arrive. Sans cela, la liste clignote à vide. */
    tag.textContent = '#' + ids[i]
    row.appendChild(tag)

    var remove = document.createElement('a')
    remove.className = 'btn btn-danger btn-xs meteobelgiqueirmDropAction'
    remove.setAttribute('data-cmd-id', ids[i])
    remove.innerHTML = '<i class="fas fa-times"></i>'
    row.appendChild(remove)

    box.appendChild(row)
    meteobelgiqueirmNameAction(ids[i], tag)
  }
}

function meteobelgiqueirmNameAction(_id, _tag) {
  if (typeof jeedom === 'undefined' || !isset(jeedom.cmd) || !isset(jeedom.cmd.byId)) { return }
  jeedom.cmd.byId({
    id: _id,
    error: function () {
      /* Commande supprimée depuis : le dire plutôt que de laisser un numéro
         qui ne correspond plus à rien. */
      _tag.className = 'label label-danger'
      _tag.textContent = '#' + _id + ' {{(introuvable)}}'
    },
    success: function (result) {
      _tag.textContent = result.human !== undefined ? result.human : ('#' + _id)
    }
  })
}

function meteobelgiqueirmAddAction() {
  if (typeof jeedom === 'undefined' || !isset(jeedom.cmd) || !isset(jeedom.cmd.getSelectModal)) { return }
  jeedom.cmd.getSelectModal({ cmd: { type: 'action' } }, function (result) {
    if (!isset(result.cmd) || !isset(result.cmd.id) || result.cmd.id === '') { return }
    var id = parseInt(String(result.cmd.id).replace('#', ''), 10)
    if (isNaN(id) || id <= 0) { return }
    id = String(id)
    var ids = meteobelgiqueirmActionIds()
    /* Deux fois la même action enverrait deux fois le même message. */
    for (var i = 0; i < ids.length; i++) {
      if (ids[i] === id) { return }
    }
    ids.push(id)
    meteobelgiqueirmSetActionIds(ids)
  })
}

function meteobelgiqueirmDropAction(_id) {
  var ids = meteobelgiqueirmActionIds()
  var kept = []
  for (var i = 0; i < ids.length; i++) {
    if (ids[i] !== String(_id)) { kept.push(ids[i]) }
  }
  meteobelgiqueirmSetActionIds(kept)
}

function meteobelgiqueirmTestAlert() {
  var id = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  if (id === null || id.value === '') {
    jeedomUtils.showAlert({ message: '{{Enregistrez la commune avant de tester l\'alerte.}}', level: 'warning' })
    return
  }
  if (meteobelgiqueirmActionIds().length === 0) {
    jeedomUtils.showAlert({ message: '{{Ajoutez au moins une action avant de tester.}}', level: 'warning' })
    return
  }
  /* L'essai part du serveur avec la configuration ENREGISTRÉE : une action
     ajoutée mais non sauvegardée ne serait pas prise en compte, et le dire
     évite de croire à une panne. */
  meteobelgiqueirmAjax('testAlert', { id: id.value }, function (result) {
    jeedomUtils.showAlert({
      message: '{{Message d\'essai envoyé à}} ' + result.result + ' {{action(s).}}',
      level: 'success'
    })
  })
}

/* ============================================================== PRÉVISIONS */

function meteobelgiqueirmClear() {
  var daily = meteobelgiqueirmEl('table_meteobelgiqueirmDaily')
  if (daily !== null) { daily.innerHTML = '' }
  var hourly = meteobelgiqueirmEl('table_meteobelgiqueirmHourly')
  if (hourly !== null) { hourly.innerHTML = '' }
  var warnings = meteobelgiqueirmEl('div_meteobelgiqueirmWarnings')
  if (warnings !== null) { warnings.innerHTML = '' }
  var fetched = meteobelgiqueirmEl('div_meteobelgiqueirmFetched')
  if (fetched !== null) {
    fetched.textContent = '{{Chargement…}}'
    fetched.className = 'alert alert-info'
  }
}

function meteobelgiqueirmRender(_data) {
  /* --- Bandeau de fraîcheur : c'est lui qui dit si l'on regarde une vraie
     donnée ou un souvenir. --- */
  var fetched = meteobelgiqueirmEl('div_meteobelgiqueirmFetched')
  if (fetched !== null) {
    if (!_data.fetched) {
      fetched.textContent = '{{Aucun relevé pour l\'instant. La météo sera lue au prochain passage, dans dix minutes au plus.}}'
      fetched.className = 'alert alert-warning'
    } else {
      fetched.textContent = _data.city + ' — {{relevé du}} ' + _data.fetched
        + ' (' + _data.age + ' {{min}})'
      fetched.className = (_data.age >= 60) ? 'alert alert-danger' : 'alert alert-info'
    }
  }

  /* --- Les prochains jours --- */
  var daily = meteobelgiqueirmEl('table_meteobelgiqueirmDaily')
  if (daily !== null) {
    daily.innerHTML = ''
    for (var i = 0; i < _data.days.length; i++) {
      var d = _data.days[i]
      var tr = document.createElement('tr')
      tr.appendChild(meteobelgiqueirmCell(d.label))

      var td = document.createElement('td')
      var icon = document.createElement('i')
      icon.className = d.icon
      td.appendChild(icon)
      td.appendChild(document.createTextNode(' ' + (d.condition || '')))
      tr.appendChild(td)

      tr.appendChild(meteobelgiqueirmCell(meteobelgiqueirmNum(d.tmin, ' °C')))
      tr.appendChild(meteobelgiqueirmCell(meteobelgiqueirmNum(d.tmax, ' °C')))
      tr.appendChild(meteobelgiqueirmCell(meteobelgiqueirmNum(d.rain_chance, ' %')))
      tr.appendChild(meteobelgiqueirmCell(d.text))
      daily.appendChild(tr)
    }
  }

  /* --- Heure par heure. Les échéances passées restent affichées, grisées :
     elles aident à situer l'instant présent dans la série. --- */
  var hourly = meteobelgiqueirmEl('table_meteobelgiqueirmHourly')
  if (hourly !== null) {
    hourly.innerHTML = ''
    for (var j = 0; j < _data.hourly.length; j++) {
      var h = _data.hourly[j]
      var row = document.createElement('tr')
      if (h.past) { row.style.opacity = '0.45' }
      row.appendChild(meteobelgiqueirmCell(h.time))

      var cell = document.createElement('td')
      var hicon = document.createElement('i')
      hicon.className = h.icon
      cell.appendChild(hicon)
      cell.appendChild(document.createTextNode(' ' + (h.condition || '')))
      row.appendChild(cell)

      row.appendChild(meteobelgiqueirmCell(meteobelgiqueirmNum(h.temp, ' °C')))
      row.appendChild(meteobelgiqueirmCell(meteobelgiqueirmNum(h.rain_chance, ' %')))
      row.appendChild(meteobelgiqueirmCell(meteobelgiqueirmNum(h.wind_speed, ' km/h')))
      hourly.appendChild(row)
    }
  }

  /* --- Avertissements --- */
  var warnings = meteobelgiqueirmEl('div_meteobelgiqueirmWarnings')
  if (warnings !== null) {
    warnings.innerHTML = ''
    if (_data.warnings.length === 0) {
      var none = document.createElement('div')
      none.className = 'alert alert-success'
      none.textContent = '{{Aucun avertissement en cours ni annoncé pour cette commune.}}'
      warnings.appendChild(none)
    }
    for (var k = 0; k < _data.warnings.length; k++) {
      var w = _data.warnings[k]
      var box = document.createElement('div')
      /* Trois niveaux, deux styles d'alerte disponibles : le jaune prend
         « warning », l'orange et le rouge prennent « danger ». La couleur ne
         porte jamais seule l'information, le niveau est écrit en toutes lettres. */
      box.className = 'alert ' + (w.level >= 2 ? 'alert-danger' : 'alert-warning')
      box.style.marginBottom = '8px'

      var title = document.createElement('b')
      title.textContent = w.levelLabel + ' — ' + w.name
        + (w.active ? ' · {{en cours}}' : ' · {{annoncé}}')
      box.appendChild(title)

      var when = document.createElement('div')
      when.textContent = '{{Du}} ' + w.from + ' {{au}} ' + w.to + ' · ' + w.slug
      box.appendChild(when)

      if (w.text) {
        var text = document.createElement('div')
        text.style.marginTop = '5px'
        text.textContent = w.text
        box.appendChild(text)
      }
      warnings.appendChild(box)
    }
  }
}

function meteobelgiqueirmLoad(_id) {
  if (!isset(_id) || _id === '') { return }
  meteobelgiqueirmAjax('data', { id: _id }, function (result) {
    meteobelgiqueirmRender(result.result)
  })
}

function meteobelgiqueirmRefresh() {
  var id = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  if (id === null || id.value === '') {
    jeedomUtils.showAlert({ message: '{{Enregistrez la commune avant de relever la météo.}}', level: 'warning' })
    return
  }
  meteobelgiqueirmStatus('{{Lecture en cours…}}', 'default')
  meteobelgiqueirmAjax('refresh', { id: id.value }, function (result) {
    meteobelgiqueirmStatus('{{Relevé effectué.}}', 'success')
    meteobelgiqueirmRender(result.result)
  })
}

/* ==================================================== APPELÉES PAR LE COEUR */

function printEqLogic(_eqLogic) {
  /* Le coeur ne réinitialise que les .eqLogicAttr : sans cela, tout le reste de
     l'écran garderait l'état de la commune précédemment ouverte. */
  meteobelgiqueirmResetSearch()
  meteobelgiqueirmClear()
  meteobelgiqueirmStatus('', '')
  meteobelgiqueirmShowCommune()
  meteobelgiqueirmShowActions()

  if (isset(_eqLogic.id) && _eqLogic.id !== '') {
    meteobelgiqueirmLoad(_eqLogic.id)
  }
}

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }

  var tr = '<td>'
  /* Sans ce champ, chaque enregistrement détruit puis recrée les commandes :
     l'historique est perdu et les scénarios pointent dans le vide. */
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked>{{Historiser}}</label>'
  tr += '<span class="cmdAttr" data-l1key="htmlstate" style="display:inline-block;margin-left:5px;"></span>'
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '</td>'

  /* Ligne créée en DOM : insertAdjacentHTML sur une table génère un <tbody> par
     insertion, et toutes les commandes se retrouveraient dans la même ligne. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.setAttribute('title', '{{Identifiant interne}} : ' + init(_cmd.logicalId))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  /* L'ordre compte : changeType après setJeeValues, jamais l'inverse. */
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* ============================================================== ÉCOUTEURS */

/* Les pages de plugin sont chargées en ajax : DOMContentLoaded a déjà eu lieu
   quand ce script s'exécute. Les écouteurs sont donc posés à la racine, par
   délégation sur un conteneur qui, lui, existe déjà. */
var meteobelgiqueirmContainer = document.getElementById('div_pageContainer') || document.body

meteobelgiqueirmContainer.addEventListener('click', function (_event) {
  var target = _event.target
  if (target === null) { return }

  if (target.closest('#bt_meteobelgiqueirmSearch') !== null) {
    _event.preventDefault()
    meteobelgiqueirmSearch()
    return
  }
  if (target.closest('#bt_meteobelgiqueirmRefresh') !== null) {
    _event.preventDefault()
    meteobelgiqueirmRefresh()
    return
  }
  if (target.closest('#bt_meteobelgiqueirmAddAction') !== null) {
    _event.preventDefault()
    meteobelgiqueirmAddAction()
    return
  }
  if (target.closest('#bt_meteobelgiqueirmTestAlert') !== null) {
    _event.preventDefault()
    meteobelgiqueirmTestAlert()
    return
  }
  var drop = target.closest('.meteobelgiqueirmDropAction')
  if (drop !== null) {
    _event.preventDefault()
    meteobelgiqueirmDropAction(drop.getAttribute('data-cmd-id'))
    return
  }
  if (target.closest('#bt_meteobelgiqueirmHideHidden') !== null) {
    _event.preventDefault()
    var rows = document.querySelectorAll('#table_cmd tbody tr.cmd')
    for (var i = 0; i < rows.length; i++) {
      var box = rows[i].querySelector('.cmdAttr[data-l1key="isVisible"]')
      if (box !== null && !box.checked) {
        rows[i].style.display = (rows[i].style.display === 'none') ? '' : 'none'
      }
    }
  }
})

meteobelgiqueirmContainer.addEventListener('change', function (_event) {
  if (_event.target !== null && _event.target.id === 'sel_meteobelgiqueirmCommune') {
    meteobelgiqueirmPickCommune(_event.target)
  }
})

/* Entrée vaut clic sur la loupe : sans cela, taper puis appuyer sur Entrée
   enregistrerait l'équipement au lieu de lancer la recherche. */
meteobelgiqueirmContainer.addEventListener('keydown', function (_event) {
  if (_event.target !== null && _event.target.id === 'in_meteobelgiqueirmSearch' && _event.key === 'Enter') {
    _event.preventDefault()
    meteobelgiqueirmSearch()
  }
})
