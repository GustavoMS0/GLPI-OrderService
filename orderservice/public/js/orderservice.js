/**
 * ------------------------------------------------------------------------
 * Order Service - Canvas de assinatura PNG e calculadora de itens
 *
 * Copyright (C) 2026 by G. Martins (GPLv3+)
 * ------------------------------------------------------------------------
 */

(function (root) {
  'use strict';

  var PNG_PREFIX = 'data:image/png;base64,';

  /**
   * Converte string decimal ("1.234,56" ou "19.99") para inteiro x100 sem ponto flutuante.
   *
   * @param {string} raw
   * @returns {number}
   */
  function parseDecimalToX100(raw) {
    if (typeof raw !== 'string') {
      raw = raw === null || raw === undefined ? '' : String(raw);
    }

    var clean = raw.trim().replace(/[^0-9.,\-]/g, '');
    if (clean === '' || clean === '-') {
      return 0;
    }

    if (clean.charAt(0) === '-') {
      return 0;
    }
    clean = clean.replace(/-/g, '');

    var lastComma = clean.lastIndexOf(',');
    var lastDot = clean.lastIndexOf('.');
    var intRaw = '';
    var fracRaw = '';

    if (lastComma !== -1 && lastDot !== -1) {
      if (lastComma > lastDot) {
        intRaw = clean.slice(0, lastComma).replace(/[.,]/g, '');
        fracRaw = clean.slice(lastComma + 1).replace(/[.,]/g, '');
      } else {
        intRaw = clean.slice(0, lastDot).replace(/[.,]/g, '');
        fracRaw = clean.slice(lastDot + 1).replace(/[.,]/g, '');
      }
    } else if (lastComma !== -1) {
      var commaCount = (clean.match(/,/g) || []).length;
      if (commaCount === 1) {
        intRaw = clean.slice(0, lastComma);
        fracRaw = clean.slice(lastComma + 1);
      } else {
        intRaw = clean.replace(/,/g, '');
      }
    } else if (lastDot !== -1) {
      var dotCount = (clean.match(/\./g) || []).length;
      if (dotCount === 1) {
        intRaw = clean.slice(0, lastDot);
        fracRaw = clean.slice(lastDot + 1);
      } else {
        intRaw = clean.replace(/\./g, '');
      }
    } else {
      intRaw = clean;
    }

    var intDigits = intRaw.replace(/\D/g, '');
    var fracDigits = fracRaw.replace(/\D/g, '');
    var intVal = intDigits !== '' ? parseInt(intDigits, 10) : 0;

    while (fracDigits.length < 3) {
      fracDigits += '0';
    }

    var cents = parseInt(fracDigits.slice(0, 2), 10);
    var third = parseInt(fracDigits.slice(2, 3), 10);
    var total = intVal * 100 + cents;

    if (third >= 5) {
      total += 1;
    }
    return total;
  }

  /**
   * Calcula total do item em centavos com arredondamento half-up.
   *
   * @param {number} quantityX100
   * @param {number} unitPriceCents
   * @returns {number}
   */
  function computeItemTotalCents(quantityX100, unitPriceCents) {
    var q = Math.max(0, parseInt(quantityX100, 10) || 0);
    var u = Math.max(0, parseInt(unitPriceCents, 10) || 0);
    return Math.floor((q * u + 50) / 100);
  }

  /**
   * Formata valor inteiro em centavos para texto monetário.
   *
   * @param {number} cents
   * @param {string} currencySymbol
   * @returns {string}
   */
  function formatCents(cents, currencySymbol) {
    var val = parseInt(cents, 10) || 0;
    var sign = val < 0 ? '-' : '';
    var abs = Math.abs(val);
    var major = Math.floor(abs / 100);
    var minor = abs % 100;
    var majorStr = String(major).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    var minorStr = minor < 10 ? '0' + String(minor) : String(minor);
    var symbol = typeof currencySymbol === 'string' && currencySymbol.trim() !== ''
      ? currencySymbol.trim()
      : 'R$';
    return sign + symbol + ' ' + majorStr + ',' + minorStr;
  }

  /**
   * Valida formato básico de Data URI PNG no cliente.
   *
   * @param {string} value
   * @returns {boolean}
   */
  function isPngDataUri(value) {
    if (typeof value !== 'string') {
      return false;
    }
    var trimmed = value.trim();
    if (trimmed.length <= PNG_PREFIX.length) {
      return false;
    }
    if (trimmed.slice(0, PNG_PREFIX.length).toLowerCase() !== PNG_PREFIX) {
      return false;
    }
    var b64 = trimmed.slice(PNG_PREFIX.length);
    return /^[A-Za-z0-9+/=]+$/.test(b64);
  }

  function getPoint(canvas, evt) {
    var rect = canvas.getBoundingClientRect();
    var clientX = evt.clientX;
    var clientY = evt.clientY;
    if (evt.touches && evt.touches.length > 0) {
      clientX = evt.touches[0].clientX;
      clientY = evt.touches[0].clientY;
    }
    var scaleX = rect.width > 0 ? canvas.width / rect.width : 1;
    var scaleY = rect.height > 0 ? canvas.height / rect.height : 1;
    return {
      x: (clientX - rect.left) * scaleX,
      y: (clientY - rect.top) * scaleY
    };
  }

  function initSignaturePad(pad) {
    if (!pad || pad.getAttribute('data-os-bound') === '1') {
      return false;
    }

    var canvas = pad.querySelector('canvas.os-signature-canvas');
    var inputId = pad.getAttribute('data-input-id') || '';
    var hiddenInput = inputId !== '' ? pad.ownerDocument.getElementById(inputId) : null;
    var clearBtn = pad.querySelector('.os-clear-sig');
    var statusEl = pad.querySelector('.os-signature-status');

    if (!canvas || !hiddenInput || typeof canvas.getContext !== 'function') {
      return false;
    }

    var ctx = canvas.getContext('2d');
    if (!ctx) {
      return false;
    }

    pad.setAttribute('data-os-bound', '1');
    var doc = pad.ownerDocument || (typeof document !== 'undefined' ? document : null);
    var statusOk = pad.getAttribute('data-status-ok') || 'Signature captured';
    var statusEmpty = pad.getAttribute('data-status-empty') || 'Draw signature above';
    var drawing = false;
    var hasStroke = false;

    ctx.lineWidth = 2.2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#0f172a';

    function setStatusText(text) {
      if (statusEl) {
        statusEl.textContent = text;
      }
    }

    function syncInput() {
      if (!hasStroke) {
        hiddenInput.value = '';
        return;
      }
      var dataUrl = canvas.toDataURL('image/png');
      if (isPngDataUri(dataUrl)) {
        hiddenInput.value = dataUrl;
      }
    }

    pad._osSyncInput = syncInput;

    function startDraw(evt) {
      evt.preventDefault();
      if (typeof canvas.setPointerCapture === 'function' && evt.pointerId !== undefined) {
        try {
          canvas.setPointerCapture(evt.pointerId);
        } catch (err) {
          /* ignore unsupported pointer capture */
        }
      }
      var pt = getPoint(canvas, evt);
      drawing = true;
      ctx.beginPath();
      ctx.moveTo(pt.x, pt.y);
      ctx.lineTo(pt.x + 0.1, pt.y + 0.1);
      ctx.stroke();
      hasStroke = true;
      setStatusText(statusOk);
    }

    function moveDraw(evt) {
      if (!drawing) {
        return;
      }
      evt.preventDefault();
      var pt = getPoint(canvas, evt);
      ctx.lineTo(pt.x, pt.y);
      ctx.stroke();
    }

    function endDraw() {
      if (!drawing) {
        return;
      }
      drawing = false;
      syncInput();
    }

    if (typeof window !== 'undefined' && 'PointerEvent' in window) {
      canvas.addEventListener('pointerdown', startDraw);
      canvas.addEventListener('pointermove', moveDraw);
      canvas.addEventListener('pointerup', endDraw);
      canvas.addEventListener('pointercancel', endDraw);
      if (doc && typeof doc.addEventListener === 'function') {
        doc.addEventListener('pointerup', endDraw);
        doc.addEventListener('pointercancel', endDraw);
      }
      if (root && typeof root.addEventListener === 'function') {
        root.addEventListener('pointerup', endDraw);
        root.addEventListener('pointercancel', endDraw);
      }
    } else {
      canvas.addEventListener('mousedown', startDraw);
      canvas.addEventListener('mousemove', moveDraw);
      canvas.addEventListener('mouseup', endDraw);
      canvas.addEventListener('touchstart', startDraw, { passive: false });
      canvas.addEventListener('touchmove', moveDraw, { passive: false });
      canvas.addEventListener('touchend', endDraw);
      if (doc && typeof doc.addEventListener === 'function') {
        doc.addEventListener('mouseup', endDraw);
        doc.addEventListener('touchend', endDraw);
      }
      if (root && typeof root.addEventListener === 'function') {
        root.addEventListener('mouseup', endDraw);
        root.addEventListener('touchend', endDraw);
      }
    }

    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        hasStroke = false;
        hiddenInput.value = '';
        setStatusText(statusEmpty);
      });
    }

    var form = pad.closest('form');
    if (form && form.getAttribute('data-os-submit-bound') !== '1') {
      form.setAttribute('data-os-submit-bound', '1');
      form.addEventListener('submit', function (evt) {
        var pads = form.querySelectorAll('.os-signature-pad');
        var i;
        for (i = 0; i < pads.length; i += 1) {
          if (typeof pads[i]._osSyncInput === 'function') {
            pads[i]._osSyncInput();
          }
        }
        for (i = 0; i < pads.length; i += 1) {
          var p = pads[i];
          var id = p.getAttribute('data-input-id') || '';
          var req = p.getAttribute('data-required') === '1';
          var inp = id !== '' ? form.ownerDocument.getElementById(id) : null;
          if (req && (!inp || !isPngDataUri(inp.value))) {
            evt.preventDefault();
            var st = p.querySelector('.os-signature-status');
            if (st) {
              st.textContent = p.getAttribute('data-msg-required') || 'Signature required';
            }
            return false;
          }
        }
        return true;
      });
    }

    return true;
  }

  function initSignaturePads(container) {
    if (!container || typeof container.querySelectorAll !== 'function') {
      return 0;
    }
    var pads = container.querySelectorAll('.os-signature-pad');
    var count = 0;
    for (var i = 0; i < pads.length; i += 1) {
      if (initSignaturePad(pads[i])) {
        count += 1;
      }
    }
    return count;
  }

  function initOrderCalculators(container) {
    if (!container || typeof container.querySelectorAll !== 'function') {
      return 0;
    }
    var forms = container.querySelectorAll('.os-add-item-form');
    var count = 0;
    for (var i = 0; i < forms.length; i += 1) {
      (function (form) {
        if (form.getAttribute('data-os-calc-bound') === '1') {
          return;
        }
        var qtyEl = form.querySelector('.os-calc-qty');
        var unitEl = form.querySelector('.os-calc-unit');
        var previewEl = form.querySelector('.os-calc-preview');
        if (!qtyEl || !unitEl || !previewEl) {
          return;
        }
        form.setAttribute('data-os-calc-bound', '1');
        var currency = previewEl.getAttribute('data-currency') || 'R$';

        function update() {
          var q = parseDecimalToX100(qtyEl.value);
          var u = parseDecimalToX100(unitEl.value);
          var total = computeItemTotalCents(q, u);
          previewEl.textContent = formatCents(total, currency);
        }

        qtyEl.addEventListener('input', update);
        unitEl.addEventListener('input', update);
        update();
        count += 1;
      }(forms[i]));
    }
    return count;
  }

  function initPrintButtons(container) {
    if (!container || typeof container.querySelectorAll !== 'function') {
      return 0;
    }
    var buttons = container.querySelectorAll('.os-print-trigger');
    var count = 0;
    for (var i = 0; i < buttons.length; i += 1) {
      var btn = buttons[i];
      if (btn.getAttribute('data-os-print-bound') === '1') {
        continue;
      }
      btn.setAttribute('data-os-print-bound', '1');
      btn.addEventListener('click', function () {
        if (typeof root.print === 'function') {
          root.print();
        }
      });
      count += 1;
    }
    return count;
  }

  function initAll(container) {
    var target = container || (typeof document !== 'undefined' ? document : null);
    if (!target) {
      return;
    }
    initSignaturePads(target);
    initOrderCalculators(target);
    initPrintButtons(target);
  }

  var api = {
    parseDecimalToX100: parseDecimalToX100,
    computeItemTotalCents: computeItemTotalCents,
    formatCents: formatCents,
    isPngDataUri: isPngDataUri,
    initSignaturePads: initSignaturePads,
    initOrderCalculators: initOrderCalculators,
    initPrintButtons: initPrintButtons,
    initAll: initAll
  };

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
  } else {
    root.OrderServiceUI = api;
  }

  if (typeof document !== 'undefined') {
    var bootstrap = function () {
      initAll(document);
      if (typeof MutationObserver !== 'undefined' && document.body) {
        var observer = new MutationObserver(function () {
          initAll(document);
        });
        observer.observe(document.body, { childList: true, subtree: true });
      }
    };

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
      bootstrap();
    }
  }
}(typeof globalThis !== 'undefined' ? globalThis : this));
