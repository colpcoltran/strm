/*
 * Technická bezpečnost – progressive enhancement dotazníku.
 * Bez JavaScriptu stránka funguje také: rozbalování řeší CSS (:has)
 * a formuláře se odesílají klasickým POSTem na api/submit.php.
 */
(function () {
  'use strict';

  var doc = document;
  var root = doc.documentElement;
  root.classList.add('js');

  var supportsHas = false;
  try {
    supportsHas = !!(window.CSS && CSS.supports && CSS.supports('selector(:has(*))'));
  } catch (err) {
    supportsHas = false;
  }
  if (!supportsHas) {
    root.classList.add('no-has');
  }

  var poll = doc.querySelector('.poll');
  if (!poll || !window.fetch) {
    return;
  }

  var radioAno = doc.getElementById('ans-ano');
  var radioNe = doc.getElementById('ans-ne');
  var panelAno = poll.querySelector('.panel-ano');
  var panelNe = poll.querySelector('.panel-ne');
  var statusEl = doc.getElementById('form-status');

  var reduceMotion = window.matchMedia
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var FOCUS_DELAY = reduceMotion ? 0 : 380;

  var MSG = {
    jmeno: 'Vyplňte prosím jméno.',
    prijmeni: 'Vyplňte prosím příjmení.',
    profese: 'Vyplňte prosím profesi.',
    emailEmpty: 'Zadejte prosím svou e-mailovou adresu.',
    emailInvalid: 'Zkontrolujte prosím formát e-mailové adresy (např. jmeno@firma.cz).',
    tooLong: 'Zadaný text je příliš dlouhý.',
    network: 'Odeslání se nezdařilo. Zkontrolujte prosím připojení a zkuste to znovu.',
    server: 'Odeslání se nezdařilo. Zkuste to prosím za chvíli znovu.',
    successAno: 'Děkujeme za registraci, budete informováni o vývoji tohoto projektu nejpozději do konce listopadu 2026.',
    successNe: 'Děkujeme za váš čas a upřímnou odpověď. I ta nám pomáhá rozhodnout o podobě projektu.'
  };

  /* --- Přepínání ANO/NE ------------------------------------ */

  function syncPanels() {
    if (!supportsHas) {
      panelAno.classList.toggle('open', radioAno.checked);
      panelNe.classList.toggle('open', radioNe.checked);
    }
  }

  function onChoice() {
    syncPanels();
    if (radioAno.checked) {
      window.setTimeout(function () {
        var first = doc.getElementById('jmeno');
        if (first) {
          first.focus();
        }
      }, FOCUS_DELAY);
    }
  }

  radioAno.addEventListener('change', onChoice);
  radioNe.addEventListener('change', onChoice);

  /* --- Chybové stavy polí ----------------------------------- */

  function errorElFor(input) {
    return doc.getElementById('err-' + input.id);
  }

  function setFieldError(input, message) {
    input.setAttribute('aria-invalid', 'true');
    var el = errorElFor(input);
    if (el) {
      el.textContent = message;
      el.hidden = false;
    }
  }

  function clearErrors(form) {
    var invalid = form.querySelectorAll('[aria-invalid="true"]');
    for (var i = 0; i < invalid.length; i++) {
      invalid[i].removeAttribute('aria-invalid');
    }
    var errs = form.querySelectorAll('.field-error');
    for (var j = 0; j < errs.length; j++) {
      errs[j].hidden = true;
      errs[j].textContent = '';
    }
  }

  function validateAno(form) {
    var errors = [];
    var fields = ['jmeno', 'prijmeni', 'profese'];
    for (var i = 0; i < fields.length; i++) {
      var input = form.querySelector('[name="' + fields[i] + '"]');
      var value = input.value.trim();
      if (value === '') {
        errors.push({ input: input, message: MSG[fields[i]] });
      } else if (value.length > 100) {
        errors.push({ input: input, message: MSG.tooLong });
      }
    }
    var email = form.querySelector('[name="email"]');
    var emailValue = email.value.trim();
    if (emailValue === '') {
      errors.push({ input: email, message: MSG.emailEmpty });
    } else if (emailValue.length > 254 || !/^\S+@\S+\.\S+$/.test(emailValue)) {
      errors.push({ input: email, message: MSG.emailInvalid });
    }
    return errors;
  }

  function applyServerErrors(form, serverErrors, banner) {
    var known = false;
    for (var name in serverErrors) {
      if (!Object.prototype.hasOwnProperty.call(serverErrors, name)) {
        continue;
      }
      var input = form.querySelector('[name="' + name + '"]');
      if (input) {
        setFieldError(input, serverErrors[name]);
        if (!known) {
          input.focus();
          known = true;
        }
      }
    }
    if (!known) {
      showBanner(banner, MSG.server);
    }
  }

  function showBanner(banner, message) {
    banner.textContent = message;
    banner.hidden = false;
  }

  /* --- Success ----------------------------------------------- */

  function showSuccess(isAno) {
    poll.classList.add('poll-done');
    var box = doc.createElement('div');
    box.className = 'success-box';
    box.innerHTML = '<svg class="success-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
      + ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<circle cx="12" cy="12" r="10"></circle><path d="M8 12.5l2.5 2.5L16 9"></path></svg>';
    var text = doc.createElement('p');
    text.textContent = isAno ? MSG.successAno : MSG.successNe;
    box.appendChild(text);
    statusEl.textContent = '';
    statusEl.appendChild(box);
    statusEl.tabIndex = -1;
    statusEl.focus();
  }

  /* --- Odeslání přes fetch ----------------------------------- */

  function wireForm(form) {
    // S JavaScriptem validujeme sami; nativní bubliny by dublovaly hlášky.
    form.noValidate = true;
    var button = form.querySelector('button[type="submit"]');
    var banner = form.querySelector('.form-error');
    var isAno = form.id === 'form-ano';

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (form.dataset.busy === '1') {
        return;
      }
      banner.hidden = true;
      clearErrors(form);

      if (isAno) {
        var errors = validateAno(form);
        if (errors.length) {
          for (var i = 0; i < errors.length; i++) {
            setFieldError(errors[i].input, errors[i].message);
          }
          errors[0].input.focus();
          return;
        }
      }

      form.dataset.busy = '1';
      button.disabled = true;
      var originalLabel = button.textContent;
      button.textContent = 'Odesílám…';

      function done() {
        form.dataset.busy = '';
        button.disabled = false;
        button.textContent = originalLabel;
      }

      fetch(form.getAttribute('action'), {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: new URLSearchParams(new FormData(form))
      }).then(function (response) {
        return response.json().then(function (data) {
          return { status: response.status, data: data };
        });
      }).then(function (result) {
        done();
        if (result.data && result.data.ok) {
          showSuccess(isAno);
          return;
        }
        if (result.status === 422 && result.data && result.data.errors) {
          applyServerErrors(form, result.data.errors, banner);
          return;
        }
        showBanner(banner, (result.data && result.data.message) || MSG.server);
      }).catch(function () {
        done();
        showBanner(banner, MSG.network);
      });
    });
  }

  wireForm(doc.getElementById('form-ano'));
  wireForm(doc.getElementById('form-ne'));

  /* --- Odkazy na zásady otevřou <details> -------------------- */

  var zasady = doc.getElementById('zasady');
  if (zasady) {
    doc.addEventListener('click', function (event) {
      var target = event.target;
      var link = target.closest ? target.closest('a[href="#zasady"]') : null;
      if (link) {
        zasady.open = true;
      }
    });
  }
}());
