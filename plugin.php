<?php

class KokenCart extends KokenPlugin {
	private $site_data;
	private $is_album;

	function __construct()
	{
		$this->require_setup = true;
		$this->site_data = new stdClass;
		$this->is_album = true;
		$this->blacklist_templates = array('albums', 'sets', 'set');
		$this->item_ids = array();

		$this->database_fields = array(
			'content' => array(
				'koken_cart_data' => array(
					'type' => 'VARCHAR',
					'constraint' => 255,
					'null' => true,
					'default' => '{"purchasable":0}'
				)
			)
		);

		$this->register_filter('site.api_data', 'filter_api');
		$this->register_hook('before_closing_body', 'render_into_foot');
		$this->register_hook('before_closing_head', 'render_into_head');
		$this->register_hook('after_pjax', 'render_after_pjax');
	}

  function currency_code_to_symbol($code)
  {

		$symbols = json_decode('{ "ALL": "Lek", "AFN": "؋", "ARS": "$", "AWG": "ƒ", "AUD": "$", "AZN": "ман", "BSD": "$", "BBD": "$", "BYN": "Br", "BZD": "BZ$", "BMD": "$", "BOB": "$b", "BAM": "KM", "BWP": "P", "BGN": "лв", "BRL": "R$", "BND": "$", "KHR": "៛", "CAD": "$", "KYD": "$", "CLP": "$", "CNY": "¥", "COP": "$", "CRC": "₡", "HRK": "kn", "CUP": "₱", "CZK": "Kč", "DKK": "kr", "DOP": "RD$", "XCD": "$", "EGP": "£", "SVC": "$", "EUR": "€", "FKP": "£", "FJD": "$", "GHS": "¢", "GIP": "£", "GTQ": "Q", "GGP": "£", "GYD": "$", "HNL": "L", "HKD": "$", "HUF": "Ft", "ISK": "kr", "IDR": "Rp", "IRR": "﷼", "IMP": "£", "ILS": "₪", "JMD": "J$", "JPY": "¥", "JEP": "£", "KZT": "лв", "KPW": "₩", "KRW": "₩", "KGS": "лв", "LAK": "₭", "LBP": "£", "LRD": "$", "MKD": "ден", "MYR": "RM", "MUR": "₨", "MXN": "$", "MNT": "₮", "MZN": "MT", "NAD": "$", "NPR": "₨", "ANG": "ƒ", "NZD": "$", "NIO": "C$", "NGN": "₦", "KPW": "₩", "NOK": "kr", "OMR": "﷼", "PKR": "₨", "PAB": "B/.", "PYG": "Gs", "PEN": "S/.", "PHP": "₱", "PLN": "zł", "QAR": "﷼", "RON": "lei", "RUB": "руб", "SHP": "£", "SAR": "﷼", "RSD": "Дин.", "SCR": "₨", "SGD": "$", "SBD": "$", "SOS": "S", "ZAR": "R", "KRW": "₩", "LKR": "₨", "SEK": "kr", "CHF": "CHF", "SRD": "$", "SYP": "£", "TWD": "NT$", "THB": "฿", "TTD": "TT$", "TVD": "$", "UAH": "₴", "GBP": "£", "USD": "$", "UYU": "$U", "UZS": "лв", "VEF": "Bs", "VND": "₫", "YER": "﷼", "ZWD": "Z$" }');

    if (isset($symbols->{$code})) {
    	$symbol = $symbols->{$code};
    }
    else {
    	$symbol = '';
    }

    return $symbol;
  }

	function add_to_site_data($item) {
		$itm = json_decode($item['koken_cart_data']) ?: new stdClass;
		$itm->cache_path = $item['cache_path'];
		$itm->title = !empty($item['title']) ? $item['title'] : $item['filename'];
		$itm->id = $item['id'];
		$itm->width = $item['width'];
		$itm->height = $item['height'];

		if ($this->data->koken_cart_digital && empty($itm->digital_price) && !empty($this->data->koken_cart_digital_price))
		{
			$itm->digital_price = (int) $this->data->koken_cart_digital_price;
		}

		if (!$this->data->koken_cart_digital || empty($itm->digital_price))
		{
			unset($itm->digital_price);
		}

		if ($this->data->koken_cart_custom_variants)
		{
			if (!isset($itm->variants))
			{
				$itm->variants = array();
			}

			$sparse_variants = array();
			foreach ($itm->variants as $varient)
			{
				$i = (int) $varient->id - 1;
				$sparse_variants[$i] = (array) $varient;
			}
			$itm->variants = $sparse_variants;

			for ($i = 1; $i <= 10; $i++)
			{
				if (empty($this->data->{'koken_cart_variant_description_' . $i}) ||
					(empty($itm->variants[$i - 1]) && empty($this->data->{'koken_cart_variant_price_' . $i})))
				{
					break;
				}

				if (empty($itm->variants[$i - 1]))
				{
					$itm->variants[$i - 1] = array(
						'id' => $i,
						'price' => (int) $this->data->{'koken_cart_variant_price_' . $i},
					);
				}
			}

			$itm->variants = array_values($itm->variants);

			if (empty($itm->variants)) {
				unset($itm->variants);
			}
		} else
		{
			unset($itm->variants);
		}

		if (($itm->purchasable === 1 || $this->data->koken_cart_purchasable === 'all') && !in_array($itm->id, $this->item_ids)) {
			unset($itm->purchasable);
			$this->site_data->content[] = $itm;
			$this->item_ids[] = $itm->id;
		}
	}

	function filter_api($data)
	{
		if (isset($data['page_type']) || !isset(Koken::$location['template']) || in_array(Koken::$location['template'], $this->blacklist_templates))
		{
			return $data;
		}

		if (!empty($data['koken_cart_data']) && isset($data['cache_path']))
		{
			$this->is_album = false;
			if (!isset($this->site_data->content))
			{
				$this->site_data->content = array();
			}

			$this->add_to_site_data($data);
		}
		else if (!empty($data['content']) && $this->is_album)
		{
			if (!isset($this->site_data->content))
			{
				$this->site_data->content = array();
			}

			foreach($data['content'] as $item)
			{
				if (!empty($item['koken_cart_data']) && isset($item['cache_path']))
				{
					$this->add_to_site_data($item);
				}
			}
		}
		else if (!empty($data['events']))
		{
			foreach($data['events'] as $event)
			{
				if (isset($event['items']))
				{
					foreach($event['items'] as $item)
					{
						if (!empty($item['koken_cart_data']) && isset($item['cache_path']))
						{
							$this->add_to_site_data($item);
						}
					}
				}
			}
		}

		return $data;
	}


	function get_bt_client_token($environment = 'sandbox')
	{
		require_once('libraries/braintree/lib/Braintree.php');

		switch($environment)
		{
			case 'sandbox':
				Braintree_Configuration::environment('sandbox');
				Braintree_Configuration::merchantId($this->data->koken_cart_bt_sandbox_merchant_id);
				Braintree_Configuration::publicKey($this->data->koken_cart_bt_sandbox_public_key);
				Braintree_Configuration::privateKey($this->data->koken_cart_bt_sandbox_private_key);
				break;
			case 'production':
				Braintree_Configuration::environment('production');
				Braintree_Configuration::merchantId($this->data->koken_cart_bt_production_merchant_id);
				Braintree_Configuration::publicKey($this->data->koken_cart_bt_production_public_key);
				Braintree_Configuration::privateKey($this->data->koken_cart_bt_production_private_key);
				break;
		}

		try
		{
			$client_token = Braintree_ClientToken::generate();
		}
		catch (Exception $e)
		{
			return false;
		}

		return $client_token;
	}

	function render_into_head()
	{
		if ($this->data->koken_cart_purchasable === 'none' || (!Koken::$draft && !$this->data->koken_cart_active) ) { return; }

		echo <<<OUT
<link rel="stylesheet" type="text/css" href="{$this->get_path()}/site/plugin.css">
OUT;
	}

	function render_into_foot()
	{
		if ($this->data->koken_cart_purchasable === 'none' || (!Koken::$draft && !$this->data->koken_cart_active) ) { return; }

		$isProduction = $this->data->koken_cart_active && !Koken::$draft;
		$processor = $this->data->koken_cart_processor;

		switch($processor)
		{
			case 'stripe':
				$publicKey = $isProduction ? $this->data->koken_cart_public_key : $this->data->koken_cart_test_public_key;
				$processor_script = 'https://checkout.stripe.com/checkout.js';
				break;
			case 'braintree':
				$bt_environment = $isProduction ? 'production' : 'sandbox';
				$publicKey = $this->get_bt_client_token($bt_environment);
				$processor_script = 'https://js.braintreegateway.com/js/braintree-2.32.1.min.js';
				break;
		}

		if (empty($publicKey)) {
			return;
		}

		$this->site_data->currency = array(
			'code' => $this->data->koken_cart_currency,
			'symbol' => $this->currency_code_to_symbol($this->data->koken_cart_currency),
		);
		$this->site_data->processor = $processor;
		$this->site_data->pk = $publicKey;
		$this->site_data->live = $isProduction;
		$this->site_data->chargeEP = $this->get_path() . '/site/charge.php';
		$this->site_data->shipping = $this->data->koken_cart_shipping;
		$this->site_data->label = Koken::out('language.purchase');

		if ($this->data->koken_cart_custom_variants)
		{
			$this->site_data->variants = array();

			for ($i = 1; $i <= 10; $i++)
			{
				if (empty($this->data->{'koken_cart_variant_description_' . $i}))
				{
					break;
				}

				$this->site_data->variants[] = array(
					'id' => $i,
					'description' => $this->data->{'koken_cart_variant_description_' . $i},
				);
			}
		}

		$js_data = json_encode($this->site_data);


		if ($processor === 'braintree')
		{
			echo <<<OUT
<iframe id="braintree-frame" src="{$this->get_path()}/site/braintree.html"></iframe>
OUT;
		}
		else
		{
			echo <<<OUT
<script src="{$processor_script}"></script>
OUT;
		}

		echo <<<OUT
<script src="{$this->get_path()}/site/plugin.js"></script>
<script type="text/javascript" class="k-cart-script">
if ('kokenCartPlugin' in window) {
	kokenCartPlugin.init({$js_data});
}
</script>
OUT;
	}

	function render_after_pjax()
	{
		if ($this->data->koken_cart_purchasable === 'none' || (!Koken::$draft && !$this->data->koken_cart_active) ) { return; }

		$js_data = json_encode($this->site_data->content);

		echo <<<OUT
<script type="text/javascript">
if ('kokenCartPlugin' in window) {
	kokenCartPlugin.add({$js_data});
}
</script>
OUT;
	}

}