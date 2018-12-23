<?php

error_reporting(0);
define('BASEPATH', true);

ini_set('default_charset', 'UTF-8');

if (function_exists('mb_internal_encoding'))
{
  mb_internal_encoding('UTF-8');
}

$root_path = dirname(dirname(dirname(dirname(dirname(__FILE__)))));
@include $root_path . '/storage/configuration/user_setup.php';
require $root_path . '/app/koken/Shutter/Shutter.php';

if (!defined('LOOPBACK_HOST_HEADER'))
{
  define('LOOPBACK_HOST_HEADER', false);
}

Shutter::enable();

$is_ssl = isset($_SERVER['HTTPS']) ? $_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] === 1 : $_SERVER['SERVER_PORT'] == 443;
$protocol = $is_ssl ? 'https' : 'http';
$real_base_folder = preg_replace('~/storage/plugins/.*/charge\.php(.*)?$~', '', $_SERVER['SCRIPT_NAME']);

require($root_path . '/app/site/Koken.php');

Koken::start();
Koken::$protocol = $protocol;
Koken::$location = array(
  'real_root_folder' => $real_base_folder,
);

$is_live = isset($_POST['live']) ? $_POST['live'] === 'true' : false;
$defaults = Shutter::get_php_object('KokenCart')->get_data();

$processor = $defaults->koken_cart_processor;
switch ($processor)
{
  case 'stripe':
    require_once('../libraries/stripe/init.php');

    $privateKey = $defaults->koken_cart_active && $is_live ? $defaults->koken_cart_private_key : $defaults->koken_cart_test_private_key;
    \Stripe\Stripe::setApiKey($privateKey);

    break;
  case 'braintree':
    require_once('../libraries/braintree/lib/Braintree.php');

    $environment = $defaults->koken_cart_active && $is_live ? 'production' : 'sandbox';

    switch($environment)
    {
      case 'sandbox':
        Braintree_Configuration::environment('sandbox');
        Braintree_Configuration::merchantId($defaults->koken_cart_bt_sandbox_merchant_id);
        Braintree_Configuration::publicKey($defaults->koken_cart_bt_sandbox_public_key);
        Braintree_Configuration::privateKey($defaults->koken_cart_bt_sandbox_private_key);
        break;
      case 'production':
        Braintree_Configuration::environment('production');
        Braintree_Configuration::merchantId($defaults->koken_cart_bt_production_merchant_id);
        Braintree_Configuration::publicKey($defaults->koken_cart_bt_production_public_key);
        Braintree_Configuration::privateKey($defaults->koken_cart_bt_production_private_key);
        break;
    }

    break;
}

$read_token = Shutter::get_encryption_key();

$content = Koken::api('/content/' . $_POST['content_id'] . '/token:' . $read_token);
$cart_data = json_decode($content['koken_cart_data']) ?: new stdClass;
$variant_id = isset($_POST['variant_id']) ? $_POST['variant_id'] : 'download';
$is_download = $variant_id === 'download';
$price = null;

if ($defaults->koken_cart_digital && $is_download)
{
  $price = isset($cart_data->digital_price) ? $cart_data->digital_price : $defaults->koken_cart_digital_price;
}
else if ($defaults->koken_cart_custom_variants && $variant_id)
{
  // Default price
  $price = $defaults->{'koken_cart_variant_price_' . $variant_id};

  // Overload if instance price
  if (isset($cart_data->variants))
  {
    $variant_id = intval($variant_id);
    foreach($cart_data->variants as $variant) {
      if ($variant_id === $variant->id) {
        $price = $variant->price;
        break;
      }
    }
  }
}

$description = !empty($content['title']) ? $content['title'] : $content['filename'];

$meta = array(
  "koken_id" => $content['id'],
  "filename" => $content['filename'],
);

if ($is_download)
{
  $meta['option'] = 'Download';
  $description .= ' (Download)';
}
else if ($variant_id)
{
  $option = $defaults->{'koken_cart_variant_description_' . $variant_id};
  $meta['option'] = $option;
  $description .= ' (' . $option . ')';
}

$currency = $defaults->koken_cart_currency;
$zeroDecimalCurrencies = array("BIF","CLP","DJF","GNF","JPY","KMF","KRW","MGA","PYG","RWF","VND","VUV","XAF","XOF","XPF");
$isZeroDecimalCurrency = in_array($currency, $zeroDecimalCurrencies);

switch($processor)
{
  case 'stripe':
    try
    {
      $multiplier = $isZeroDecimalCurrency ? 1 : 100;
      $charge = \Stripe\Charge::create(array(
        "amount" => floor(floatval($price) * $multiplier),
        "currency" => $currency,
        "source" => $_POST['token'],
        "description" => $description,
        "receipt_email" => $_POST['email'],
        "metadata" => $meta,
      ));

      if ($is_download)
      {
        $data = array(
          'url' => $real_base_folder . '/dl.php?src=' . $content['original']['relative_url']
        );

        header('Content-Type: application/json');
        echo json_encode($data);
      }
      else
      {
        header('Content-Type: application/json', true, 200);
      }
    }
    catch (Exception $e)
    {
      if (substr(get_class($e), 0, 13) === 'Stripe\\Error\\') {
        $http_response_code = $e->httpStatus;
        header('Content-Type: application/json', true, $http_response_code);
        echo json_encode($e->getJsonBody());
      } else {
        header('Content-Type: application/json', true, 402);
      }
    }

    break;

  case 'braintree':
    $nonce = $_POST['token'];
    $decimals = $isZeroDecimalCurrency ? 0 : 2;
    $amount = number_format(floatval($price), $decimals, ".", "");

    $item = array(
      'amount' => $amount,
      'paymentMethodNonce' => $nonce,
      'options' => array(
        'submitForSettlement' => true
      ),
      'customFields' => $meta
    );

    if (isset($defaults->koken_cart_shipping) && isset($_POST['address']))
    {
      $item['shipping'] = array(
        'firstName' => $_POST['address']['first-name'],
        'lastName' => $_POST['address']['last-name'],
        'streetAddress' => $_POST['address']['street'],
        'countryCodeAlpha2' => $_POST['address']['country'],
        'locality' => $_POST['address']['city']
      );

      if (isset($_POST['address']['postal-code']))
      {
        $item['shipping']['postalCode'] = $_POST['address']['postal-code'];
      }

      if (isset($_POST['address']['state']))
      {
        $item['shipping']['region'] = $_POST['address']['state'];
      }
    }

    if (isset($_POST['email']))
    {
      $item['customer'] = array(
        'email' => $_POST['email']
      );

      if (isset($_POST['address'])) {
        $item['customer']['firstName'] = $_POST['address']['first-name'];
        $item['customer']['lastName'] = $_POST['address']['last-name'];
      }
    }

    $sale = Braintree_Transaction::sale($item);

    if ($sale->success || !is_null($sale->transaction))
    {
      if ($is_download)
      {
        $data = array(
          'url' => $real_base_folder . '/dl.php?src=' . $content['original']['relative_url']
        );

        header('Content-Type: application/json');
        echo json_encode($data);
      }
      else
      {
        header('Content-Type: application/json', true, 200);
      }
    }
    else
    {
      $data = new stdClass;
      $data->errors = array();

      foreach($sale->errors->deepAll() as $error) {
        $data->errors[] = array(
          'code' => $error->code,
          'message' => $error->message
        );
      }
      header('Content-Type: application/json', true, 402);
      echo json_encode($data);
    }

    break;
}