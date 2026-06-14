(function () {
  function onlyDigits(value) {
    return (value || '').replace(/\D+/g, '');
  }

  function formatPostcode(value) {
    var digits = onlyDigits(value).slice(0, 8);
    if (digits.length > 5) {
      return digits.slice(0, 5) + '-' + digits.slice(5);
    }
    return digits;
  }

  function findCartForm(container) {
    var summary = container.closest('.summary');
    return (summary ? summary.querySelector('form.cart') : null) || document.querySelector('form.cart');
  }

  function selectedVariationId(cartForm) {
    if (!cartForm) {
      return 0;
    }
    var input = cartForm.querySelector('input[name="variation_id"]');
    return input ? parseInt(input.value || '0', 10) || 0 : 0;
  }

  function selectedQuantity(cartForm) {
    if (!cartForm) {
      return 1;
    }
    var input = cartForm.querySelector('input.qty[name="quantity"], input[name="quantity"]');
    return input ? Math.max(1, parseInt(input.value || '1', 10) || 1) : 1;
  }

  function hasVariationForm(cartForm) {
    return !!(cartForm && cartForm.classList.contains('variations_form'));
  }

  function setMessage(result, message, type) {
    result.className = 'correios-seller-product-shipping__result is-' + (type || 'info');
    result.textContent = message;
  }

  function renderRates(result, rates) {
    if (!rates.length) {
      setMessage(result, CorreiosSellerProductShipping.i18n.empty, 'empty');
      return;
    }

    result.className = 'correios-seller-product-shipping__result has-rates';
    result.innerHTML = '';

    var list = document.createElement('ul');
    list.className = 'correios-seller-product-shipping__rates';

    rates.forEach(function (rate) {
      var item = document.createElement('li');
      item.className = 'correios-seller-product-shipping__rate';

      var main = document.createElement('span');
      main.className = 'correios-seller-product-shipping__rate-main';

      var label = document.createElement('span');
      label.className = 'correios-seller-product-shipping__rate-label';
      label.textContent = rate.label;

      var cost = document.createElement('span');
      cost.className = 'correios-seller-product-shipping__rate-cost';
      cost.innerHTML = rate.cost_html;

      main.appendChild(label);
      main.appendChild(cost);
      item.appendChild(main);

      if (rate.delivery_label || rate.description) {
        var meta = document.createElement('span');
        meta.className = 'correios-seller-product-shipping__rate-meta';
        meta.textContent = rate.delivery_label || rate.description;
        item.appendChild(meta);
      }

      list.appendChild(item);
    });

    result.appendChild(list);
  }

  function initSimulator(container) {
    var form = container.querySelector('.correios-seller-product-shipping__form');
    var postcodeInput = container.querySelector('.correios-seller-product-shipping__postcode');
    var result = container.querySelector('.correios-seller-product-shipping__result');
    var button = container.querySelector('.correios-seller-product-shipping__button');

    if (!form || !postcodeInput || !result || !button) {
      return;
    }

    postcodeInput.addEventListener('input', function () {
      postcodeInput.value = formatPostcode(postcodeInput.value);
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();

      var postcode = onlyDigits(postcodeInput.value);
      if (postcode.length !== 8) {
        setMessage(result, CorreiosSellerProductShipping.i18n.invalidPostcode, 'error');
        return;
      }

      var cartForm = findCartForm(container);
      var variationId = selectedVariationId(cartForm);
      if (hasVariationForm(cartForm) && !variationId) {
        setMessage(result, CorreiosSellerProductShipping.i18n.chooseVariation, 'error');
        return;
      }

      var data = new FormData();
      data.append('action', CorreiosSellerProductShipping.action);
      data.append('nonce', CorreiosSellerProductShipping.nonce);
      data.append('product_id', container.getAttribute('data-product-id') || '0');
      data.append('variation_id', String(variationId));
      data.append('quantity', String(selectedQuantity(cartForm)));
      data.append('postcode', postcode);

      button.disabled = true;
      setMessage(result, CorreiosSellerProductShipping.i18n.loading, 'loading');

      fetch(CorreiosSellerProductShipping.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: data
      })
        .then(function (response) {
          return response.json();
        })
        .then(function (payload) {
          if (!payload || !payload.success) {
            throw new Error(payload && payload.data && payload.data.message ? payload.data.message : CorreiosSellerProductShipping.i18n.error);
          }
          renderRates(result, payload.data.rates || []);
        })
        .catch(function (error) {
          setMessage(result, error.message || CorreiosSellerProductShipping.i18n.error, 'error');
        })
        .finally(function () {
          button.disabled = false;
        });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.correios-seller-product-shipping').forEach(initSimulator);
  });
})();
