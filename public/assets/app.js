/*
 * Technická bezpečnost – progressive enhancement registrace.
 * Na stránce jsou dva registrační formuláře: skrytý v hero (rozbalí
 * a sbalí ho CTA „Mám zájem") a plný dole v sekci #registrace, který
 * je vidět vždy. Po úspěšném odeslání kdekoli se obě místa přepnou na
 * poděkování. Bez JavaScriptu vede CTA na dolní formulář a formuláře
 * se odesílají klasickým POSTem (PHP vrátí samostatnou stránku).
 */
(function () {
  'use strict';

  var doc = document;
  doc.documentElement.classList.add('js');

  var heroSection = doc.querySelector('.hero');
  var heroPanel = doc.getElementById('hero-panel-ano');
  var statusHero = doc.getElementById('hero-status');
  var statusBottom = doc.getElementById('form-status');
  var registrace = doc.getElementById('registrace');
  var cta = doc.querySelector('a[data-vyber="ANO"]');

  var reduceMotion = window.matchMedia
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var OPEN_DELAY = reduceMotion ? 0 : 380;

  /* Po úspěšném odeslání se už nic dalšího neodesílá (obě místa
     zobrazí poděkování); během requestu je vše zamčené. */
  var answered = false;
  var busyGlobal = false;

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

  /* --- CTA v hero ----------------------------------------------
     „Mám zájem" rozbalí registrační formulář přímo v hero; druhý klik
     (nebo Escape v panelu) ho zase sbalí. ARIA disclosure (role button,
     aria-expanded, aria-controls) nastavuje až JS – bez něj je CTA
     obyčejný odkaz na vždy viditelný dolní formulář (#registrace). */
  var setHeroPanel = function (open) {
    heroPanel.classList.toggle('open', open);
    cta.setAttribute('aria-expanded', String(open));
    if (open) {
      window.setTimeout(function () {
        var first = doc.getElementById('jmeno-h');
        if (first) {
          first.focus();
        }
      }, OPEN_DELAY);
    } else if (heroPanel.contains(doc.activeElement) || doc.activeElement === doc.body) {
      // Fokus nesmí spadnout do skrytého panelu ani na <body>.
      cta.focus();
    }
  };

  if (cta && heroPanel) {
    cta.setAttribute('role', 'button');
    cta.setAttribute('aria-expanded', 'false');
    cta.setAttribute('aria-controls', 'hero-panel-ano');

    cta.addEventListener('click', function (event) {
      event.preventDefault();
      if (busyGlobal || answered) {
        return;
      }
      setHeroPanel(!heroPanel.classList.contains('open'));
    });

    // Odkaz s rolí tlačítka musí reagovat i na mezerník (Enter dělá odkaz sám).
    cta.addEventListener('keydown', function (event) {
      if (event.key === ' ' || event.key === 'Spacebar' || event.keyCode === 32) {
        event.preventDefault();
        cta.click();
      }
    });

    heroPanel.addEventListener('keydown', function (event) {
      if ((event.key === 'Escape' || event.key === 'Esc' || event.keyCode === 27)
        && heroPanel.classList.contains('open') && !busyGlobal) {
        event.preventDefault();
        setHeroPanel(false);
        cta.focus();
      }
    });
  }

  /* Deep-link (např. #registrace) při studené cache: po doskočení webfontu
     se změní výška obsahu nad cílem – po načtení fontů cíl znovu zarovnáme. */
  if (window.location.hash && doc.fonts && doc.fonts.ready) {
    doc.fonts.ready.then(function () {
      var target = doc.getElementById(window.location.hash.slice(1));
      if (target && target.scrollIntoView) {
        target.scrollIntoView();
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
    profese: 'Vyplňte prosím profesi či oblast zájmu.',
    emailEmpty: 'Zadejte prosím svou e-mailovou adresu.',
    emailInvalid: 'Zkontrolujte prosím formát e-mailové adresy (např. jmeno@firma.cz).',
    tooLong: 'Zadaný text je příliš dlouhý.',
    network: 'Odeslání se nezdařilo. Zkontrolujte prosím připojení a zkuste to znovu.',
    server: 'Odeslání se nezdařilo. Zkuste to prosím za chvíli znovu.',
    success: 'Děkujeme za registraci, budete informováni o vývoji tohoto projektu nejpozději do konce listopadu 2026.'
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

  function validate(form) {
    var errors = [];
    var fields = ['jmeno', 'prijmeni', 'profese'];
    for (var i = 0; i < fields.length; i++) {
      var input = form.querySelector('[name="' + fields[i] + '"]');
      if (!input) {
        continue;
      }
      var value = input.value.trim();
      if (value === '') {
        errors.push({ input: input, message: MSG[fields[i]] });
      } else if (value.length > 100) {
        errors.push({ input: input, message: MSG.tooLong });
      }
    }
    var email = form.querySelector('[name="email"]');
    if (!email) {
      return errors;
    }
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

  function renderSuccess(container) {
    if (!container) {
      return;
    }
    var box = doc.createElement('div');
    box.className = 'success-box';
    box.innerHTML = '<svg class="success-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
      + ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<circle cx="12" cy="12" r="10"></circle><path d="M8 12.5l2.5 2.5L16 9"></path></svg>';
    var text = doc.createElement('p');
    text.textContent = MSG.success;
    box.appendChild(text);
    container.textContent = '';
    container.appendChild(box);
  }

  function showSuccess(originStatus) {
    answered = true;
    if (registrace) {
      registrace.classList.add('answered');
    }
    if (heroSection) {
      heroSection.classList.add('hero-answered');
    }
    if (cta) {
      cta.setAttribute('aria-expanded', 'false');
    }
    if (heroPanel) {
      heroPanel.classList.remove('open');
    }
    renderSuccess(statusBottom);
    renderSuccess(statusHero);
    var focusTarget = originStatus || statusBottom;
    if (focusTarget) {
      focusTarget.tabIndex = -1;
      focusTarget.focus();
    }
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
    if (!button || !banner) {
      return;
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (busyGlobal || answered) {
        return;
      }
      banner.hidden = true;
      clearErrors(form);

      var errors = validate(form);
      if (errors.length) {
        for (var i = 0; i < errors.length; i++) {
          setFieldError(errors[i].input, errors[i].message);
        }
        errors[0].input.focus();
        return;
      }

      busyGlobal = true;
      button.disabled = true;
      var originalLabel = button.textContent;
      button.textContent = 'Odesílám…';

      function done(restoreFocus) {
        busyGlobal = false;
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
          showSuccess(originStatus);
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
  wireForm(doc.getElementById('form-ano-hero'), statusHero);
}());
