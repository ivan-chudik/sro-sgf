/* Christmas Nitra 2026 - interactive checkout steps.
 *
 * Drives the block rendered by includes/christmas-nitra-checkout.php. That
 * block is Roman's own markup minus the sales-page-only parts: the same
 * pricing math and the same nudge ladder, writing into the checkout form
 * instead of an add-to-cart link.
 *
 * It renders no total of its own. The panel beside the steps is WooCommerce's
 * own order review, dressed as Roman's "Tvoja vstupenka" in
 * christmas-nitra-checkout.php and xnt-checkout.css, so the price on screen is
 * the price the order has - not a second one computed here.
 *
 * Three responsibilities, in this order of importance:
 *
 *   1. Keep the hidden selection inputs in sync with the visible state. They
 *      are the whole contract with the shared plugin: WooCommerce serializes
 *      the checkout form on update_order_review and
 *      SelectionManager::saveToSession rewrites the session from it, so a
 *      state the inputs do not carry is a state the order will not have.
 *   2. Fire update_checkout after every change, so adjustPricing recomputes
 *      people x tier[day_count] without a page reload.
 *   3. Step 1 changes the product, not just the form, so it goes through the
 *      xnt_switch_type AJAX endpoint first and only then does 1 and 2.
 *
 * Everything binds on `document`, never on the block: the shared plugin
 * replaces #alttag-selection-ui wholesale on the event switch, which would
 * strip any wrapper-scoped listener and leave the steps dead until a reload.
 *
 * No price and no visible string is written here - both come out of
 * window.XNT_CHECKOUT, which PHP builds from the product meta and the copy map.
 */
(function ($) {
  'use strict';

  var cfg = window.XNT_CHECKOUT;
  if (!cfg || !cfg.types || !cfg.fields) {
    return;
  }

  var UPDATE_DELAY = 400;
  var T = cfg.text || {};
  var updateTimer = null;
  var switching = false;
  var resubmit = false;
  var resubmitFailSafe = null;

  /* ── formatting ───────────────────────────────────────────────────────── */

  /* WooCommerce's own price format, so the preview and the order review next
     to it never disagree about separators or where the symbol goes. */
  function money(value) {
    var m = cfg.money || {};
    var decimals = m.decimals === undefined ? 2 : m.decimals;
    var parts = Math.abs(value).toFixed(decimals).split('.');
    var whole = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, m.thousandSep || '');
    var number = parts[1] ? whole + (m.decimalSep || '.') + parts[1] : whole;
    var out = (m.format || '%1$s%2$s')
      .replace('%1$s', m.symbol || '')
      .replace('%2$s', number);
    return (value < 0 ? '-' : '') + out;
  }

  /* Slovak has three forms (1 / 2-4 / 5+); English collapses the last two. */
  function plural(count, stem) {
    var index = count === 1 ? 0 : (count >= 2 && count <= 4 ? 1 : 2);
    return T[stem + index] || '';
  }

  function counted(count, stem) {
    return count + ' ' + plural(count, stem);
  }

  /* Templates carry %1 / %2 / %3 for prices and %p for the per-person suffix. */
  function fmt(template, values) {
    var out = template || '';
    Object.keys(values || {}).forEach(function (token) {
      out = out.split(token).join(values[token]);
    });
    return out;
  }

  /* ── state ────────────────────────────────────────────────────────────── */

  function blocks() {
    return Array.prototype.slice.call(document.querySelectorAll('.xnt-co'));
  }

  function dayRows(block) {
    return Array.prototype.slice.call(block.querySelectorAll('.xnt-day'));
  }

  function dayInputs(block) {
    return Array.prototype.slice.call(block.querySelectorAll('input[data-xnt-day]'));
  }

  function readState(block) {
    var days = [];
    var indexes = [];
    dayInputs(block).forEach(function (input, index) {
      if (input.checked) {
        days.push(input.value);
        indexes.push(index);
      }
    });

    var output = block.querySelector('.xnt-co-people');
    var people = parseInt(output ? output.textContent : '1', 10);
    var type = block.getAttribute('data-type') || 'live';
    // A livestream ticket is one account: the people step is not rendered for
    // it, and the posted selection must never carry more than one person.
    if (type === 'stream') {
      people = 1;
    }

    return {
      productId: parseInt(block.getAttribute('data-product-id'), 10) || 0,
      type: type,
      days: days,
      indexes: indexes,
      people: isNaN(people) ? 1 : people
    };
  }

  /* ── pricing ──────────────────────────────────────────────────────────── */

  /* Per-person price for a day count, with the same fallback
     SelectionManager::calculateMatrixPrice uses: the highest tier at or below
     the selected day count. Returns null when the product has no tier at all. */
  function fromMap(prices, dayCount) {
    prices = prices || {};
    if (prices[dayCount] !== undefined) {
      return parseFloat(prices[dayCount]);
    }

    var best = null;
    var value = null;
    Object.keys(prices).forEach(function (key) {
      var days = parseInt(key, 10);
      if (days <= dayCount && (best === null || days > best)) {
        best = days;
        value = parseFloat(prices[key]);
      }
    });
    return value;
  }

  function unitPrice(type, dayCount) {
    return fromMap((cfg.types[type] || {}).prices, dayCount);
  }

  function fullPrice(type, dayCount) {
    return fromMap((cfg.types[type] || {}).pricesFull, dayCount);
  }

  /* ── hidden inputs ────────────────────────────────────────────────────── */

  /* The hidden inputs sit inside form.checkout, the visible block above it, so
     the two are paired by cart-line index rather than by nesting. */
  function fieldsFor(block) {
    return document.querySelector(
      '.xnt-co-fields[data-xnt-index="' + (block.getAttribute('data-xnt-index') || '0') + '"]'
    );
  }

  /* Rebuild the hidden inputs from scratch - the product id changes under a
     type swap, so patching values in place would leave the old product keyed. */
  function writeFields(block, state) {
    var container = fieldsFor(block);
    if (!container) {
      return;
    }
    container.textContent = '';

    function add(name, value) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = value;
      container.appendChild(input);
    }

    state.days.forEach(function (date) {
      add(cfg.fields.days + '[' + state.productId + '][]', date);
      add(cfg.fields.daysData + '[' + state.productId + '][' + date + ']', '1');
    });

    add(cfg.fields.participants + '[]', cfg.fields.participantType);
    add(cfg.fields.participantsData + '[' + cfg.fields.participantType + ']', String(state.people));
  }

  /* ── render ───────────────────────────────────────────────────────────── */

  /* The upsell ladder from xnt.js, priced from the checkout config. Only the
     four-day event the copy talks about gets one. */
  function renderNudge(block, state) {
    var box = block.querySelector('.xnt-nudge');
    var text = block.querySelector('.xnt-co-nudge-t');
    var button = block.querySelector('.xnt-co-nudge-b');
    if (!box || !text || !button) {
      return;
    }

    var all = dayInputs(block).length;
    var n = state.days.length;
    box.classList.remove('hot');
    button.hidden = true;
    if (all !== 4) {
      box.hidden = true;
      return;
    }
    box.hidden = false;

    var P = function (count) { return unitPrice(state.type, count) || 0; };
    var diff = function (a, b) { return money(Math.abs(P(a) - P(b))); };
    var pp = state.people > 1 ? (T.perperson || '') : '';
    var copy = '';
    var label = '';
    var pick = '';

    if (n === 0) {
      copy = T.nudge0;
      label = T.btnsat;
      pick = '3';
    } else if (n === 1) {
      copy = fmt(T.nudge1, { '%1': diff(2, 1), '%2': money(P(2)), '%3': money(P(4)), '%p': pp });
      label = T.btnall;
      pick = '1,2,3,4';
    } else if (n === 2 || (n === 3 && P(4) >= P(3))) {
      copy = fmt(T.nudge2, {
        '%1': diff(4, n),
        '%2': money(P(4)),
        '%3': money(P(1) * 4 - P(4)),
        '%p': pp
      });
      label = T.btnallsel;
      pick = '1,2,3,4';
      box.classList.add('hot');
    } else if (n === 3) {
      copy = fmt(T.nudge3, { '%1': money(P(4)), '%2': money(P(3)), '%3': diff(3, 4), '%p': pp });
      label = T.btnfourth;
      pick = '1,2,3,4';
      box.classList.add('hot');
    } else {
      copy = fmt(T.nudge4, { '%1': money(P(4)), '%2': money(P(4) / 4), '%p': pp });
    }

    text.innerHTML = copy;
    if (pick) {
      button.textContent = label;
      button.setAttribute('data-pick', pick);
      button.hidden = false;
    }
  }

  function render(block) {
    var state = readState(block);
    var n = state.days.length;

    var unit = unitPrice(state.type, 1);
    var full = fullPrice(state.type, 1);
    var perDay = unit === null ? '' : money(unit) + (full !== null && full > unit ? '<s>' + money(full) + '</s>' : '');

    dayRows(block).forEach(function (row) {
      var input = row.querySelector('input[data-xnt-day]');
      row.classList.toggle('on', !!input && input.checked);
      var price = row.querySelector('[data-pr]');
      if (price) {
        price.innerHTML = perDay;
      }
    });

    var picked = state.indexes.map(function (index) { return index + 1; }).join(',');
    Array.prototype.forEach.call(block.querySelectorAll('.xnt-quick button'), function (button) {
      button.classList.toggle('on', button.getAttribute('data-pick') === picked);
    });

    Array.prototype.forEach.call(block.querySelectorAll('.xnt-co-type'), function (button) {
      var on = button.getAttribute('data-xnt-type') === state.type;
      button.classList.toggle('on', on);
      button.setAttribute('aria-checked', on ? 'true' : 'false');
    });

    var stepper = block.querySelectorAll('.xnt-stepper button');
    if (stepper.length === 2) {
      stepper[0].disabled = state.people <= 1;
      stepper[1].disabled = state.people >= cfg.maxPeople;
    }

    var tierUnit = n ? unitPrice(state.type, n) : null;
    var total = tierUnit === null ? 0 : tierUnit * state.people;

    var hint = block.querySelector('.xnt-co-hint');
    if (hint) {
      if (state.type === 'stream') {
        /* A livestream ticket is one account, so the hint carries the days
           only - no person count and no per-person sentence. */
        hint.innerHTML = n
          ? counted(n, 'day') + ' = <b>' + money(total) + '</b>'
          : '<small>' + T.noday + '</small>';
      } else {
        hint.innerHTML = n
          ? counted(state.people, 'person') + ' × ' + counted(n, 'day')
            + ' = <b>' + money(total) + '</b><small>'
            + (state.people > 1 ? fmt(T.hintmany, { '%1': money(tierUnit) }) : T.hintone)
            + '</small>'
          : counted(state.people, 'person') + '<small>' + T.noday + '</small>';
      }
    }

    renderNudge(block, state);

    writeFields(block, state);
    return state;
  }

  function renderAll() {
    blocks().forEach(render);
  }

  function scheduleCheckoutUpdate() {
    if (updateTimer) {
      clearTimeout(updateTimer);
    }
    updateTimer = setTimeout(function () {
      updateTimer = null;
      $(document.body).trigger('update_checkout');
    }, UPDATE_DELAY);
  }

  /* Quick picks and the nudge button both set the whole selection at once. The
     day numbers are 1-based, the way Roman's data-pick and data-i are. */
  function pick(block, spec) {
    var wanted = String(spec).split(',').map(function (value) { return parseInt(value, 10); });
    dayInputs(block).forEach(function (input, index) {
      input.checked = wanted.indexOf(index + 1) !== -1;
    });
    render(block);
    scheduleCheckoutUpdate();
  }

  function showError(block, message) {
    var error = block.querySelector('.xnt-co-error');
    if (!message) {
      if (error) {
        error.remove();
      }
      return;
    }
    if (!error) {
      error = document.createElement('p');
      error.className = 'xnt-co-error';
      block.appendChild(error);
    }
    error.textContent = message;
  }

  /* ── step 1: swap the cart line ───────────────────────────────────────── */

  /* The cart line has to be replaced server side, so the block waits for the
     endpoint, re-keys itself onto the new product and only then runs the
     ordinary sync. Days travel as indexes: the two products keep their own
     _event_available_dates rows and the index, not the date string, is what
     they agree on. */
  function switchType(block, type) {
    if (switching || block.getAttribute('data-switchable') !== '1') {
      return;
    }

    var state = readState(block);
    if (state.type === type || !cfg.types[type]) {
      return;
    }

    switching = true;
    block.classList.add('is-busy');
    showError(block, '');

    var body = new URLSearchParams();
    body.append('action', cfg.action);
    body.append('nonce', cfg.nonce);
    body.append('type', type);
    body.append('people', String(state.people));
    state.indexes.forEach(function (index) {
      body.append('days[]', String(index));
    });

    fetch(cfg.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString()
    })
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        if (!payload || !payload.success || !payload.data) {
          throw new Error('switch failed');
        }

        var data = payload.data;

        // The block's own shape changes with the type (a livestream line has
        // no people step), and the billing column is not re-rendered by
        // update_order_review - so the endpoint sends the freshly rendered
        // steps and the whole root is swapped in one move.
        if (data.steps) {
          var parsed = document.createElement('div');
          parsed.innerHTML = data.steps;
          // The payload is the whole steps root (.xnt-co-steps-root), so the
          // swap replaces the root itself - matching on .xnt-co here would
          // never hit and the old steps would silently stay in place.
          var fresh = parsed.firstElementChild;
          if (fresh && fresh.classList.contains('xnt-co-steps-root') && block.closest('.xnt-co-steps-root')) {
            var root = block.closest('.xnt-co-steps-root');
            fresh.dataset.xntSwap = String(Date.now());
            root.replaceWith(fresh);
            var freshBlock = fresh.querySelector('.xnt-co');
            if (freshBlock) {
              render(freshBlock);
            }
            $(document.body).trigger('update_checkout');
            return;
          }
        }

        block.setAttribute('data-product-id', String(data.productId));
        block.setAttribute('data-type', data.type);

        // The new product carries its own dates; re-label the rows from the
        // config so a swap never needs a reload. The "Finals" badge Roman puts
        // inside .xnt-nm is kept - only the name text node is rewritten.
        var options = (cfg.types[data.type] || {}).days || [];
        dayRows(block).forEach(function (row, index) {
          var input = row.querySelector('input[data-xnt-day]');
          var option = options[index];
          if (option) {
            input.value = option.date;
            var name = row.querySelector('.xnt-nm');
            if (name) {
              if (name.firstChild && name.firstChild.nodeType === 3) {
                name.firstChild.nodeValue = option.label;
              } else {
                name.insertBefore(document.createTextNode(option.label), name.firstChild);
              }
            }
          }
          input.checked = option ? data.days.indexOf(option.date) !== -1 : false;
        });

        render(block);
        $(document.body).trigger('update_checkout');
      })
      .catch(function () {
        showError(block, T.switchfailed);
      })
      .then(function () {
        switching = false;
        block.classList.remove('is-busy');
      });
  }

  /* ── events ───────────────────────────────────────────────────────────── */

  document.addEventListener('click', function (event) {
    if (!event.target.closest) {
      return;
    }

    var typeButton = event.target.closest('.xnt-co-type[data-xnt-type]');
    if (typeButton) {
      event.preventDefault();
      var typeBlock = typeButton.closest('.xnt-co');
      if (typeBlock) {
        switchType(typeBlock, typeButton.getAttribute('data-xnt-type'));
      }
      return;
    }

    var pickButton = event.target.closest('.xnt-quick button[data-pick], .xnt-co-nudge-b[data-pick]');
    if (pickButton) {
      event.preventDefault();
      var pickBlock = pickButton.closest('.xnt-co');
      if (pickBlock) {
        pick(pickBlock, pickButton.getAttribute('data-pick'));
      }
      return;
    }

    var stepButton = event.target.closest('[data-xnt-people]');
    if (!stepButton) {
      return;
    }
    event.preventDefault();

    var block = stepButton.closest('.xnt-co');
    var output = block ? block.querySelector('.xnt-co-people') : null;
    if (!output) {
      return;
    }

    var step = parseInt(stepButton.getAttribute('data-xnt-people'), 10) || 0;
    var people = (parseInt(output.textContent, 10) || 1) + step;
    people = Math.max(1, Math.min(cfg.maxPeople, people));
    output.textContent = String(people);

    render(block);
    scheduleCheckoutUpdate();
  });

  document.addEventListener('change', function (event) {
    var input = event.target;
    if (!input || !input.matches || !input.matches('input[data-xnt-day]')) {
      return;
    }

    var block = input.closest('.xnt-co');
    if (!block) {
      return;
    }

    // Never let the last day go: an empty selection prices the line at 0 and
    // fails checkout validation, which reads as "the checkout broke".
    if (!input.checked && dayInputs(block).filter(function (i) { return i.checked; }).length === 0) {
      input.checked = true;
      return;
    }

    render(block);
    scheduleCheckoutUpdate();
  });

  // While an update is pending or a type swap is in flight the session still
  // holds the PREVIOUS selection while the block already shows the new one, so
  // an order placed in that window would be priced from the stale session.
  // Block the submit, flush the update, re-submit once updated_checkout
  // confirms the server caught up. selection-participant-types.js does exactly
  // this - but the override stands its assets down, so the guard has to be
  // repeated here.
  $('form.checkout').on('checkout_place_order.xntco', function (event) {
    if (!updateTimer && !switching) {
      return;
    }

    resubmit = true;
    if (updateTimer) {
      clearTimeout(updateTimer);
      updateTimer = null;
      $(document.body).trigger('update_checkout');
    }
    resubmitFailSafe = setTimeout(function () { resubmit = false; }, 8000);

    event.stopImmediatePropagation();
    event.preventDefault();
    return false;
  });

  // The block is re-rendered server side on the event switch, so re-sync
  // whenever WooCommerce finishes an update.
  $(document.body).on('updated_checkout.xntco', function () {
    clearTimeout(resubmitFailSafe);
    renderAll();
    if (resubmit) {
      resubmit = false;
      setTimeout(function () { $('form.checkout').trigger('submit'); }, 100);
    }
  });

  $(document.body).on('checkout_error.xntco', function () {
    resubmit = false;
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', renderAll);
  } else {
    renderAll();
  }
})(jQuery);
