/*
 * Technická bezpečnost – progressive enhancement dotazníku.
 * Na stránce jsou dva formuláře: skrytý v hero (rozbalí ho CTA
 * „Mám zájem", CTA „Nemám zájem" odešle odpověď rovnou) a plný
 * dotazník dole. Po odpovědi kdekoli se obě místa přepnou na
 * poděkování. Bez JavaScriptu vedou CTA na dolní dotazník
 * a formuláře se odesílají klasickým POSTem.
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
  if (!poll) {
    return;
  }

  var radioAno = doc.getElementById('ans-ano');
  var radioNe = doc.getElementById('ans-ne');
  var panelAno = poll.querySelector('.panel-ano');
  var panelNe = poll.querySelector('.panel-ne');
  var statusBottom = doc.getElementById('form-status');

  var heroSection = doc.querySelector('.hero');
  var heroPanel = doc.querySelector('.hero-panel');
  var statusHero = doc.getElementById('hero-status');
  var formNeHero = doc.getElementById('form-ne-hero');

  var reduceMotion = window.matchMedia
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var OPEN_DELAY = reduceMotion ? 0 : 380;

  /* Po úspěšném odeslání se už nic dalšího neodesílá (obě místa
     zobrazí poděkování); během requestu je vše zamčené. */
  var answered = false;
  var busyGlobal = false;

  function lockAll(locked) {
    busyGlobal = locked;
    radioAno.disabled = locked;
    radioNe.disabled = locked;
  }

  /* --- Přepínání ANO/NE v dolním dotazníku --------------------- */

  function syncPanels() {
    if (!supportsHas) {
      panelAno.classList.toggle('open', radioAno.checked);
      panelNe.classList.toggle('open', radioNe.checked);
    }
  }

  radioAno.addEventListener('change', syncPanels);
  radioNe.addEventListener('change', syncPanels);

  /* --- Odkazy na zásady otevřou <details> ---------------------- */

  var zasady = doc.getElementById('zasady');
  if (zasady) {
    doc.addEventListener('click', function (event) {
      var target = event.target;
      var link = target.closest
        ? target.closest('a[href="#zasady"], a[href="#zasady-text"]')
        : null;
      if (link) {
        zasady.open = true;
      }
    });
  }

  /* --- Programové odeslání formuláře --------------------------- */

  function requestSubmitForm(form) {
    if (!form || busyGlobal || answered) {
      return;
    }
    if (form.requestSubmit) {
      form.requestSubmit();
    } else {
      var submitEvent;
      try {
        submitEvent = new Event('submit', { bubbles: true, cancelable: true });
      } catch (err2) {
        submitEvent = doc.createEvent('Event');
        submitEvent.initEvent('submit', true, true);
      }
      form.dispatchEvent(submitEvent);
    }
  }

  /* Pointer klik na volbu „Ne, nemám zájem" dole odešle rovnou.
     Klávesnicová volba v radiogroup nic neodesílá (šipky slouží
     k procházení možností) – tam zůstává explicitní tlačítko. */
  var labelNe = doc.querySelector('label[for="ans-ne"]');
  if (labelNe && window.PointerEvent) {
    labelNe.addEventListener('pointerup', function () {
      window.setTimeout(function () {
        if (radioNe.checked) {
          requestSubmitForm(doc.getElementById('form-ne'));
        }
      }, 0);
    });
  }

  /* --- CTA v hero ----------------------------------------------
     „Mám zájem" rozbalí formulář přímo v hero, „Nemám zájem"
     odešle odpověď rovnou. Bez JS vedou odkazy na dolní dotazník. */
  var ctas = doc.querySelectorAll('a[data-vyber]');
  for (var c = 0; c < ctas.length; c++) {
    ctas[c].addEventListener('click', function (event) {
      event.preventDefault();
      if (busyGlobal || answered) {
        return;
      }
      if (this.getAttribute('data-vyber') === 'ANO') {
        if (heroPanel) {
          heroPanel.classList.add('open');
          window.setTimeout(function () {
            var first = doc.getElementById('jmeno-h');
            if (first) {
              first.focus();
            }
          }, OPEN_DELAY);
        }
      } else {
        if (heroPanel) {
          heroPanel.classList.remove('open');
        }
        requestSubmitForm(formNeHero);
      }
    });
  }

  /* --- Fetch odesílání jen s plnou podporou prohlížeče --------- */

  if (!window.fetch || !window.URLSearchParams || !window.FormData
    || !window.FormData.prototype || !window.FormData.prototype.entries) {
    return;
  }

  var MSG = {
    jmeno: 'Vyplňte prosím jméno.',
    prijmeni: 'Vyplňte prosím příjmení.',
    profese: 'Vyplňte prosím profesi.',
    emailEmpty: 'Zadejte prosím svou e-mailovou adresu.',
    emailInvalid: 'Zkontrolujte prosím formát e-mailové adresy (např. jmeno@firma.cz).',
    tooLong: 'Zadaný text je příliš dlouhý.',
    network: 'Odeslání se nezdařilo. Zkontrolujte prosím připojení a zkuste to znovu.',
    server: 'Odeslání se nezdařilo. Zkuste to prosím za chvíli znovu.',
    successAno: 'Děkujeme za registraci, budete informováni o vývoji tohoto projektu nejpozději do konce listopadu 2026.',
    successNe: 'Děkujeme za váš čas a upřímnou odpověď. I ta nám pomáhá rozhodnout o podobě projektu.'
  };

  /* --- Chybové stavy polí -------------------------------------- */

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
    var focused = false;
    var handled = false;
    for (var name in serverErrors) {
      if (!Object.prototype.hasOwnProperty.call(serverErrors, name)) {
        continue;
      }
      var input = form.querySelector('[name="' + name + '"]');
      var errEl = input ? errorElFor(input) : null;
      if (input && errEl) {
        setFieldError(input, serverErrors[name]);
        handled = true;
        if (!focused) {
          input.focus();
          focused = true;
        }
      }
    }
    if (!handled) {
      showBanner(banner, MSG.server);
    }
  }

  function showBanner(banner, message) {
    banner.textContent = message;
    banner.hidden = false;
  }

  /* --- Success ------------------------------------------------- */

  function renderSuccess(container, isAno) {
    if (!container) {
      return;
    }
    var box = doc.createElement('div');
    box.className = 'success-box';
    box.innerHTML = '<svg class="success-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
      + ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<circle cx="12" cy="12" r="10"></circle><path d="M8 12.5l2.5 2.5L16 9"></path></svg>';
    var text = doc.createElement('p');
    text.textContent = isAno ? MSG.successAno : MSG.successNe;
    box.appendChild(text);
    container.textContent = '';
    container.appendChild(box);
  }

  function showSuccess(isAno, originStatus) {
    answered = true;
    poll.classList.add('poll-done');
    if (heroSection) {
      heroSection.classList.add('hero-answered');
    }
    renderSuccess(statusBottom, isAno);
    renderSuccess(statusHero, isAno);
    var focusTarget = originStatus || statusBottom;
    focusTarget.tabIndex = -1;
    focusTarget.focus();
  }

  /* --- Odeslání přes fetch ------------------------------------- */

  function wireForm(form, originStatus) {
    if (!form) {
      return;
    }
    // S JavaScriptem validujeme sami; nativní bubliny by dublovaly hlášky.
    form.noValidate = true;
    var button = form.querySelector('button[type="submit"]');
    var banner = form.querySelector('.form-error');
    var isAno = form.querySelector('[name="answer"]').value === 'ANO';

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (busyGlobal || answered) {
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

      lockAll(true);
      button.disabled = true;
      var originalLabel = button.textContent;
      button.textContent = 'Odesílám…';

      function done(restoreFocus) {
        lockAll(false);
        button.disabled = false;
        button.textContent = originalLabel;
        // disabled tlačítko zahodilo fokus na <body> – vrátíme ho.
        if (restoreFocus && doc.activeElement === doc.body) {
          button.focus();
        }
      }

      fetch(form.getAttribute('action'), {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: new URLSearchParams(new FormData(form))
      }).then(function (response) {
        return response.text().then(function (raw) {
          var data = null;
          try {
            data = JSON.parse(raw);
          } catch (parseErr) {
            data = null;
          }
          return { status: response.status, data: data };
        });
      }).then(function (result) {
        if (result.data && result.data.ok) {
          done(false);
          showSuccess(isAno, originStatus);
          return;
        }
        done(true);
        if (result.status === 422 && result.data && result.data.errors) {
          applyServerErrors(form, result.data.errors, banner);
          return;
        }
        showBanner(banner, (result.data && result.data.message) || MSG.server);
      }).catch(function () {
        done(true);
        showBanner(banner, MSG.network);
      });
    });
  }

  wireForm(doc.getElementById('form-ano'), statusBottom);
  wireForm(doc.getElementById('form-ne'), statusBottom);
  wireForm(doc.getElementById('form-ano-hero'), statusHero);
  wireForm(formNeHero, statusHero);
}());
