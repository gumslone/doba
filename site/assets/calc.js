// The commission calculator. Plain arithmetic, shown in full below the
// result, because a number a hotelier cannot check is a sales pitch.
(function () {
  var root = document.getElementById('calc');
  if (!root) return;
  var $ = function (id) { return document.getElementById(id); };
  var lang = document.documentElement.lang || 'en';

  function money(n, currency) {
    return new Intl.NumberFormat(lang, { style: 'currency', currency: currency, maximumFractionDigits: 0 }).format(Math.round(n));
  }

  function run() {
    var rooms = +$('c-rooms').value, occ = +$('c-occ').value / 100, rate = +$('c-rate').value;
    var share = +$('c-share').value / 100, comm = +$('c-comm').value / 100, cur = $('c-cur').value;
    var nights = rooms * 365 * occ;
    var viaPortals = nights * rate * share;
    var paid = viaPortals * comm;

    $('v-rooms').textContent = rooms;
    $('v-occ').textContent = Math.round(occ * 100) + ' %';
    $('v-share').textContent = Math.round(share * 100) + ' %';
    $('v-comm').textContent = (comm * 100).toFixed(1).replace('.0', '') + ' %';
    $('r-paid').textContent = money(paid, cur);
    $('r-third').textContent = money(paid / 3, cur);
    $('r-nights').textContent = new Intl.NumberFormat(lang).format(Math.round(nights * share));
  }

  root.addEventListener('input', run);
  run();
})();
