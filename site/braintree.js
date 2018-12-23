var BTChargeHandler = (function() {
  var $ = parent.jQuery;
  var $view = $(this.frameElement);
  var _options;
  var _onToken = $.noop;

  function _toCurrency(amount, currency) {
    return currency.symbol + amount;
  }

  function validateEmail(email) {
    var re = /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
    return re.test(email);
  }

  function validateForm() {
    var $inputs = $('.card-field:visible', this);
    var isValid = true;

    $inputs.each(function(i, input) {
      var $input = $(input);

      if (!$input.val() || ($input.prop('name') === 'email' && !validateEmail($input.val()) ) ) {
        $input.parent().addClass('invalid');
        isValid = false;
      } else {
        $input.parent().removeClass('invalid');
      }
    });

    // clear invalids on input
    $inputs
      .off('input.validate')
      .on('input.validate', function() {
        $(this).parent().removeClass('invalid');
      });

      return isValid;
  }

  // View
  $.extend($view, {
    init: function(options) {
      var $btnClose = $('.close', document);
      var $fields = $('.card-field', document);
      var $shipping = $('.address-form', document);
      var $billing = $('.card-form', document);

      $btnClose.on('click', function(evt) {
        evt.stopPropagation();
        evt.preventDefault();

        $view.hide();
      });

      $shipping.on('submit', function(evt) {
        evt.preventDefault();

        var isValid = validateForm.call(this);

        if (isValid) {
          $shipping.hide();
          $billing.show();
          document.getElementById('braintree-dropin-frame').style.height = '252px';
        }
      });

      $shipping.find('#shipping-country').on('change', function(evt) {
        var country = this.value.toLowerCase();
        var $shipping = $('.address-form', document);
        var $postal = $('#shipping-postal-code', document);
        var $state = $('#shipping-state', document);
        var postalText = 'Postal Code';

        var noPostalCodes = ['ao','ag','aw','bs','bz','bj','bo','bw','bf','bi','cm','cf','km','cd','cg','ck','dj','dm','gq','er','fj','tf','gm','gh','gd','gn','gy','hk','ie','ci','jm','ke','ki','kp','mo','mw','ml','mr','mu','ms','nr','nu','pa','qa','rw','kn','lc','sa','sc','sl','sb','so','za','sr','sy','st','tz','tl','tk','to','tt','tv','ug','ae','vu','ye','zw'];

        if (country === 'us') {
          postalText = 'Zip Code';

          $shipping.removeClass('hide-state');
          if (!$state.val()) {
            $state.val($state.data('prev') || '');
          }
        } else {
          $shipping.addClass('hide-state');
          $state.data('prev', $state.val()).val('');
        }

        $postal
          .prop('placeholder', postalText)
          .prev('.field-name').text(postalText);

        if (noPostalCodes.indexOf(country) !== -1) {
          $shipping.addClass('hide-postal');
          $postal.data('prev', $postal.val()).val('');
        } else {
          $shipping.removeClass('hide-postal');
          if (!$postal.val().length) {
            $postal.val($postal.data('prev') || '');
          }
        }
      });

      $billing.on('submit', function(evt) {
        var isValid = validateForm.call(this);

        if (!isValid) {
          evt.stopImmediatePropagation();
          evt.preventDefault();
        }
      })

      $fields.on({
        focusin: function(evt) {
          $(this).parent().addClass('active');
        },
        focusout: function(evt) {
          $(this).parent().removeClass('active');
        },
        input: function(evt) {
          var $label = $(this).parent();
          if (this.value.length) {
            $label.addClass('show-label');
          } else {
            $label.removeClass('show-label');
          }
        }
      });
    },
    update: function(options) {
      var textOps = ['amount', 'description', 'name'];
      var $shipping = $('.address-form', document);
      var $billing = $('.card-form', document);
      var div = ["BIF","CLP","DJF","GNF","JPY","KMF","KRW","MGA","PYG","RWF","VND","VUV","XAF","XOF","XPF"].indexOf(_options.currency.code) !== -1 ? 1 : 100;

      for (op in options) {
        switch(op) {
          case 'amount':
            var price = _toCurrency(parseFloat(options['amount']) / div, _options.currency);

            $('.price', document).text(price);

            break;
          case 'description':
          case 'name':
            var $text = $('.' + op, document);

            if (options[op]) {
              $text.show().text(options[op]);
            } else {
              $text.hide();
            }
            break;
          case 'image':
            var $thumb = $('.thumb', document);

            if (options['image']) {
              $thumb.show().css('background-image', 'url(' + options['image'] + ')');
            } else {
              $thumb.hide();
            }
            break;
        }
      }

      if (_options.shippingAddress) {
        $billing.hide();
        $shipping.show();
      } else {
        $shipping.hide();
        $billing.show();
        document.getElementById('braintree-dropin-frame').style.height = '252px';
      }
    }
  });

  var chargeHandler = {
    configure: function(options) {
      _options = options;
      $view.init(options);

      braintree.setup(options.authorization, 'dropin', {
        container: 'dropin-container',
        onReady: function (integration) {
          chargeHandler._teardown = integration.teardown;
        },
        paypal: {
          button: {
            type: 'checkout'
          }
        },
        onPaymentMethodReceived: function(payload) {
          payload.email = $('#customer-email', document).val();

          if (_options.shippingAddress) {
            var $shipping = $('.address-form', document);
            var address = {};

            $.each($shipping.serializeArray(), function() {
              address[this.name] = this.value;
            });

            $.extend(payload, { 'address': address });
          }

          _onToken.call(this, payload);
        }
      });

      return chargeHandler;
    },
    open: function(options) {
      $view.update({
        amount: options.amount,
        name: options.name,
        description: options.description,
        image: options.image
      });

      _onToken = options.token;

      $view.show();
    },
    close: function() {
      $view.hide();
    },
    reload: function() {
      if (_options && this._teardown) {
        this._teardown();
        this.configure(_options);
      }
    }
  };

  return chargeHandler;
})();

if (parent.kokenCartPlugin.data) {
  parent.kokenCartPlugin.init(parent.kokenCartPlugin.data);
}