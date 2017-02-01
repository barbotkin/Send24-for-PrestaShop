<?php
/**
 *  License: GPLv3
 *
 *  @author    Roman Barbotkin
 *  @copyright 2016 Send24.com
 *  @license   LICENSE.txt
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Send24 extends CarrierModule
{
    const PREFIX = 'send24_shipping_';
    protected $hooks = array('actionCarrierUpdate');

    protected $carriers = array(
        'Send24 Sameday' => 'send24_shipping',
    );

    public $postcode = 1560;
    public $denmark = 'Denmark';
    public $express = 'Ekspres';

    public function __construct()
    {

        $this->name = 'send24';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.0';
        $this->author = 'Roman Barbotkin';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
        $this->bootstrap = true;
        $this->module_key = '129aaa3794f9c9b6eef5a1b67441ed2b';

        parent::__construct();

        $this->displayName = $this->l('Send24 Shipping');
        $this->description = $this->l('Offer your customers premium delivery service with Send24 Sameday Express solution.Easy integration into your webshop. Fast and secure delivery service from door to door.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

        // Register hook.
        $this->registerHook('header');
        $this->registerHook('newOrder');
        $this->registerHook('ActionCarrierUpdate');
        $this->registerHook('AdminOrder');
    }

    public function hookNewOrder($params)
    {
        $current_email = $this->context->customer->email;
        // Get value.
        $send24_consumer_key = Configuration::get('send24_consumer_key');
        $send24_consumer_secret = Configuration::get('send24_consumer_secret');
        // Get shipping value.
        $address = new Address($this->context->cart->id_address_delivery);

        $select_country = $this->express;

        // get/check Express.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://send24.com/wc-api/v3/get_products");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_USERPWD, $send24_consumer_key . ":" . $send24_consumer_secret);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
        ));
        $send24_countries = Tools::jsonDecode(curl_exec($ch));
        curl_close($ch);
        $n = count($send24_countries);
        for ($i = 0; $i < $n; $i++) {
            if ($send24_countries[$i]->title == $select_country) {
                $send24_product_id = $send24_countries[$i]->product_id;
                $i = $n;
                $is_available = true;
            } else {
                $is_available = false;
            }
        }

        if ($is_available == true) {
            $insurance_price = 0;
            $discount = "false";
            $ship_total = $type = $price_need = '';

            if ($select_country == $this->express) {
                $select_country = 'Danmark';
                $where_shop_id = 'ekspres';
            }

            // Create order.
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://send24.com/wc-api/v3/create_order");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_USERPWD, $send24_consumer_key . ":" . $send24_consumer_secret);
            curl_setopt($ch, CURLOPT_POSTFIELDS, '
                                            {
                                            "TO_company": "' . $address->company . '",
                                            "TO_first_name": "' . $address->firstname . '",
                                            "TO_last_name": "' . $address->lastname . '",
                                            "TO_phone": "' . $address->phone . '",
                                            "TO_email": "' . $current_email . '",
                                            "TO_country": "' . $select_country . '",
                                            "TO_city": "' . $address->city . '",
                                            "TO_postcode": "' . $address->postcode . '",
                                            "Insurance" : "' . $insurance_price . '",
                                            "Weight": "5",
                                            "TO_address": "' . $address->address1 . '",
                                            "WHAT_product_id": "' . $send24_product_id . '",
                                            "WHERE_shop_id": "' . $where_shop_id . '",
                                            "discount": "' . $discount . '",
                                            "type": "' . $type . '",
                                            "need_points": "' . $price_need . '",
                                            "total": "' . $ship_total . '",
                                            "ship_mail": "' . $current_email . '",
                                            "bill_mail": "' . $current_email . '"
                                            }
                                            ');

            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                "Content-Type: application/json",
            ));
            $response = curl_exec($ch);

            $response_order = Tools::jsonDecode($response, JSON_FORCE_OBJECT);

            if (!empty($response_order)) {
                $tracking_number = explode('?', $response_order['track']);
                $objOrder = $params['order'];
                $history = new OrderHistory();
                $history->id_order = (int) $objOrder->id;
                $order_carrier = new OrderCarrier($history->id_order);
                $order_carrier->tracking_number = $tracking_number['1'];
                Db::getInstance()->insert('send24order_value', array(
                    'id_order' => (int) $history->id_order,
                    'order_number' => $response_order['order_number'],
                    'link_to_pdf' => $response_order['link_to_pdf'],
                    'link_to_doc' => $response_order['link_to_doc'],
                    'link_to_zpl' => $response_order['link_to_zpl'],
                    'link_to_epl' => $response_order['link_to_epl'],
                    'track' => $response_order['track'],
                    'date_add' => date('Y-m-d H:i:s'),
                ));

                $order_carrier->update();
            }
            // Delete Carriers.
            // self::deleteCarriers();
            foreach ($this->carriers as $value) {
                $carriers = new Carrier((int) (Configuration::get(self::PREFIX . $value)));
                $carriers->delay = array('1' => '(viser leveringstid i dag)');
                $carriers->deleted = 0;
                $carriers->update();
            }
            curl_close($ch);
        }

        return true;
    }

    public function hookAdminOrder($params)
    {
        $id_order = $params['id_order'];
        $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'send24order_value WHERE id_order = ' . $id_order . '';

        if ($row = Db::getInstance()->getRow($sql)) {
            $html = '<div class="panel">
						<div class="panel-heading">
							<i class="icon-print"></i> Printout
						</div>
						<div id="messages" class="well hidden-print">
							<a class="btn btn-default" href="' . $row['link_to_pdf'] . '" target="_blank">
								<i class="icon-file-pdf-o"></i> PDF
							</a>
							<a class="btn btn-default" href="' . $row['link_to_doc'] . '" target="_blank">
								<i class="icon-print"></i> DOC
							</a>
							<a class="btn btn-default" href="' . $row['link_to_zpl'] . '" target="_blank">
								<i class="icon-print"></i> ZPL
							</a>
							<a class="btn btn-default" href="' . $row['link_to_epl'] . '" target="_blank">
								<i class="icon-print"></i> EPL
							</a>
						</div>
					</div>';
            return $html;
        }
    }

    public function hookHeader()
    {
        // Get time work Express.
        $start_work_express = Configuration::get('start_work_express');
        $end_work_express = Configuration::get('end_work_express');
        // Check time work.
        date_default_timezone_set('Europe/Copenhagen');
        $today = strtotime(date("Y-m-d H:i"));
        $start_time = strtotime('' . date("Y-m-d") . ' ' . $start_work_express . '');
        $end_time = strtotime('' . date("Y-m-d") . ' ' . $end_work_express . '');

        // Check on step 1 shipping value.
        if (($_SERVER['PHP_SELF'] == 'order.php'
            || Tools::getValue('controller') == 'order')
            && Tools::getValue('step') == 1
            || Tools::getValue('step') == 2) {
            // Check time setting in plugin.
            if ($start_time < $today && $end_time > $today) {
                // Get value.
                $send24_consumer_key = Configuration::get('send24_consumer_key');
                $send24_consumer_secret = Configuration::get('send24_consumer_secret');
                // Get/check Express.
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, "https://send24.com/wc-api/v3/get_products");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_USERPWD, $send24_consumer_key . ":" . $send24_consumer_secret);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    "Content-Type: application/json",
                ));
                $send24_countries = Tools::jsonDecode(curl_exec($ch));
                curl_close($ch);
                if (empty($send24_countries->errors)) {
                    $n = count($send24_countries);
                    $select_country = $this->express;
                    for ($i = 0; $i < $n; $i++) {
                        if ($send24_countries[$i]->title == $select_country) {
                            // $_SESSION['price_express'] = $send24_countries[$i]->price;
                            $context = Context::getContext();
                            $context->cookie->__set('price_express', $send24_countries[$i]->price);
                            $i = $n;
                            $is_available = true;
                        } else {
                            $is_available = false;
                        }
                    }

                    if ($is_available == true) {
                        // Get shipping value.
                        $address = new Address($this->context->cart->id_address_delivery);
                        $shipping_address_1 = $address->address1;
                        $shipping_postcode = $address->postcode;
                        $shipping_city = $address->city;
                        $shipping_country = $address->country;
                        if ($address->id_country != '20' || $shipping_country != 'Denmark') {
                            $shipping_country = $this->denmark;
                        }

                        // Get billing address user.
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, "https://send24.com/wc-api/v3/get_user_id");
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_HEADER, false);
                        curl_setopt($ch, CURLOPT_USERPWD, $send24_consumer_key . ":" . $send24_consumer_secret);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                            "Content-Type: application/json",
                        ));
                        $user_meta = Tools::jsonDecode(curl_exec($ch));

                        $billing_address_1 = $user_meta->billing_address_1['0'];
                        $billing_postcode = $user_meta->billing_postcode['0'];
                        $billing_city = $user_meta->billing_city['0'];
                        $billing_country = $user_meta->billing_country['0'];
                        if ($billing_country == 'DK') {
                            $billing_country = $this->denmark;
                        }

                        $full_b_address = "$billing_address_1, $billing_postcode $billing_city, $billing_country";
                        $full_s_address = "$shipping_address_1, $shipping_postcode $shipping_city, $shipping_country";

                        // Get billing coordinates.
                        $b = urlencode($full_b_address);
                        $billing_url = "http://maps.googleapis.com/maps/api/geocode/json?sensor=false&address=" . $b;
                        $billing_latlng = get_object_vars(Tools::jsonDecode(Tools::file_get_contents($billing_url)));
                        // Check billing address.
                        if (!empty($billing_latlng['results'])) {
                            $billing_lat = $billing_latlng['results'][0]->geometry->location->lat;
                            $billing_lng = $billing_latlng['results'][0]->geometry->location->lng;

                            // Get shipping coordinates.
                            $s = urlencode($full_s_address);
                            $shipping_url = "http://maps.googleapis.com/maps/api/geocode/json?sensor=false&address=" . $s;
                            $ship_latlng = get_object_vars(Tools::jsonDecode(Tools::file_get_contents($shipping_url)));
                            // Check shipping address.
                            if (!empty($ship_latlng['results'])) {
                                $shipping_lat = $ship_latlng['results'][0]->geometry->location->lat;
                                $shipping_lng = $ship_latlng['results'][0]->geometry->location->lng;

                                // get_is_driver_area_five_km
                                $ch = curl_init();
                                curl_setopt($ch, CURLOPT_URL, "https://send24.com/wc-api/v3/get_is_driver_area_five_km");
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch, CURLOPT_HEADER, false);
                                curl_setopt($ch, CURLOPT_POST, true);
                                curl_setopt($ch, CURLOPT_USERPWD, $send24_consumer_key . ":" . $send24_consumer_secret);
                                curl_setopt($ch, CURLOPT_POSTFIELDS, '
                                                                {
                                                                    "billing_lat": "' . $billing_lat . '",
                                                                    "billing_lng": "' . $billing_lng . '",
                                                                    "shipping_lat": "' . $shipping_lat . '",
                                                                    "shipping_lng": "' . $shipping_lng . '"
                                                                }
                                                                ');

                                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                                    "Content-Type: application/json",
                                ));

                                $response = curl_exec($ch);
                                $res = Tools::jsonDecode($response);
                                if (!empty($res)) {
                                    // Check start_time.
                                    if (!empty($res->start_time)) {
                                        $picked_up_time = strtotime('' . date("Y-m-d") . ' ' . $res->start_time . '');
                                        // Check time work from send24.com
                                        if ($start_time < $picked_up_time && $end_time > $picked_up_time) {
                                            //self::createCarriers($res->time);
                                            foreach ($this->carriers as $value) {
                                                $configuration_carriers = Configuration::get(self::PREFIX . $value);
                                                $carriers = new Carrier((int) ($configuration_carriers));
                                                $carriers->delay = array('1' => $res->end_time);
                                                if ($carriers->deleted == '1') {
                                                    $carriers->deleted = 0;
                                                    $carriers->update();
                                                    Tools::redirect('/index.php?controller=order&step=3');
                                                }
                                                $carriers->update();
                                            }
                                        } else {
                                            // If not correct time.
                                            $this->deleteShowShipping();
                                        }
                                    } else {
                                        // If empty response start_time.
                                        $this->deleteShowShipping();
                                    }

                                }
                                curl_close($ch);
                            } else {
                                // If not correct shipping address.
                                $this->deleteShowShipping();
                            }
                        } else {
                            // If not correct billing address.
                            $this->deleteShowShipping();
                        }

                    }
                }
            } else {
                $this->deleteShowShipping();
            }
        }
        // Default value.
        if (Tools::getValue('controller') != 'order') {
            foreach ($this->carriers as $value) {
                $carriers = new Carrier((int) (Configuration::get(self::PREFIX . $value)));
                preg_match('/^([0-1][0-9]|[2][0-3]):([0-5][0-9])$/si', $carriers->delay['1'], $time);
                if (!empty($time)) {
                    $carriers->delay = array('1' => '(viser leveringstid i dag)');
                    $carriers->deleted = 0;
                    $carriers->update();
                }
            }
        }
    }

    public function install()
    {
        if (parent::install()) {
            foreach ($this->hooks as $hook) {
                if (!$this->registerHook($hook)) {
                    return false;
                }
            }

            $sql_file = dirname(__FILE__) . '/install/install.sql';
            if (!$this->loadSQLFile($sql_file)) {
                return false;
            }

            // Function for creating new currier.
            if (!$this->createCarriers('(viser leveringstid i dag)')) {
                return false;
            }

            return true;
        }

        return false;
    }

    public function loadSQLFile($sql_file)
    {
        // Get install MySQL file content
        $sql_content = Tools::file_get_contents($sql_file);

        // Replace prefix and store MySQL command in array
        $sql_content = str_replace('PREFIX_', _DB_PREFIX_, $sql_content);
        $sql_requests = preg_split("/;\s*[\r\n]+/", $sql_content);

        // Execute each MySQL command
        $result = true;
        foreach ($sql_requests as $request) {
            if (!empty($request)) {
                $result &= Db::getInstance()->execute(trim($request));
            }
        }

        // Return result
        return $result;
    }

    public function deleteShowShipping()
    {
        foreach ($this->carriers as $value) {
            $carriers = new Carrier((int) (Configuration::get(self::PREFIX . $value)));
            if ($carriers->deleted == '0') {
                $carriers->deleted = 1;
                $carriers->update();
                $previousPage = $_SERVER["HTTP_REFERER"];
                Tools::redirect($previousPage);
            }
        }
    }

    protected function createCarriers($eta)
    {
        // Sameday(ETA: 18:00)
        foreach ($this->carriers as $key => $value) {
            //Create new carrier
            $carrier = new Carrier();
            $carrier->name = $key;
            $carrier->active = true;
            $carrier->deleted = 0;
            $carrier->shipping_handling = false;
            $carrier->range_behavior = 0;
            $carrier->delay[Configuration::get('PS_LANG_DEFAULT')] = $eta;
            $carrier->shipping_external = true;
            $carrier->is_module = true;
            $carrier->external_module_name = $this->name;
            $carrier->need_range = true;
            $carrier->url = 'https://send24.com/track?@';

            if ($carrier->add()) {
                $groups = Group::getGroups(true);
                foreach ($groups as $group) {
                    Db::getInstance()->autoExecute(_DB_PREFIX_ . 'carrier_group', array(
                        'id_carrier' => (int) $carrier->id,
                        'id_group' => (int) $group['id_group'],
                    ), 'INSERT');
                }

                $rangePrice = new RangePrice();
                $rangePrice->id_carrier = $carrier->id;
                $rangePrice->delimiter1 = '0';
                $rangePrice->delimiter2 = '5';
                $rangePrice->add();

                $rangeWeight = new RangeWeight();
                $rangeWeight->id_carrier = $carrier->id;
                $rangeWeight->delimiter1 = '0';
                $rangeWeight->delimiter2 = '5';
                $rangeWeight->add();

                $zones = Zone::getZones(true);

                foreach ($zones as $z) {
                    if ($z['name'] == 'Europe') {
                        Db::getInstance()->autoExecute(_DB_PREFIX_ . 'carrier_zone', array(
                            'id_carrier' => (int) $carrier->id,
                            'id_zone' => (int) $z['id_zone'],
                        ), 'INSERT');
                        Db::getInstance()->autoExecuteWithNullValues(_DB_PREFIX_ . 'delivery', array(
                            'id_carrier' => $carrier->id,
                            'id_range_price' => (int) $rangePrice->id,
                            'id_range_weight' => null,
                            'id_zone' => (int) $z['id_zone'],
                            'price' => '0',
                        ), 'INSERT');
                        Db::getInstance()->autoExecuteWithNullValues(_DB_PREFIX_ . 'delivery', array(
                            'id_carrier' => $carrier->id,
                            'id_range_price' => null,
                            'id_range_weight' => (int) $rangeWeight->id,
                            'id_zone' => (int) $z['id_zone'],
                            'price' => '0',
                        ), 'INSERT');
                    }
                }

                copy(dirname(__FILE__) . '/views/img/send24.jpg', _PS_SHIP_IMG_DIR_ . '/' . (int) $carrier->id . '.jpg');

                Configuration::updateValue(self::PREFIX . $value, $carrier->id);
                Configuration::updateValue(self::PREFIX . $value . '_reference', $carrier->id);
            }
        }

        return true;
    }

    protected function deleteCarriers()
    {
        foreach ($this->carriers as $value) {
            $tmp_carrier_id = Configuration::get(self::PREFIX . $value);
            $carrier = new Carrier($tmp_carrier_id);
            $carrier->delete();
        }
        // foreach ($this->carriers as $key => $value) {
        //     $carriers = new Carrier((int)(Configuration::get(self::PREFIX . $value)));
        //     $carriers->deleted = 1;
        //     $carriers->update();
        // }

        return true;
    }

    public function uninstall()
    {
        if (parent::uninstall()) {
            foreach ($this->hooks as $hook) {
                if (!$this->unregisterHook($hook)) {
                    return false;
                }
            }
            // Delete carrier.
            if (!$this->deleteCarriers()) {
                return false;
            }

            // Remove table.
            $sql = 'DROP TABLE `PREFIX_send24order_value`';
            $sql_query = str_replace('PREFIX_', _DB_PREFIX_, $sql);
            if (!Db::getInstance()->execute($sql_query)) {
                return false;
            }

            return true;
        }

        return false;
    }

    // Update price.
    public function getOrderShippingCost($params, $shipping_cost)
    {
        $params = $shipping_cost = '';
        $context = Context::getContext();
        return $context->cookie->price_express;
    }

    public function getOrderShippingCostExternal($params)
    {
        return $this->getOrderShippingCost($params, 0);
    }

    // The hook «ActionCarrierUpdate» keeps the reference of the original carrier.
    public function hookActionCarrierUpdate($params)
    {
        if ($params['carrier']->id_reference == Configuration::get(self::PREFIX . 'swipbox_reference')) {
            Configuration::updateValue(self::PREFIX . 'swipbox', $params['carrier']->id);
        }
    }

    // Page
    public function getContent()
    {
        $output = null;

        if (Tools::isSubmit('submit' . $this->name)) {
            $send24_title = (string) Tools::getValue('send24_title');
            $send24_consumer_key = (string) Tools::getValue('send24_consumer_key');
            $send24_consumer_secret = (string) Tools::getValue('send24_consumer_secret');
            $start_work_express = (string) Tools::getValue('start_work_express');
            $end_work_express = (string) Tools::getValue('end_work_express');
            $error = false;
            // Check title.
            if (!$send24_title
                || empty($send24_title)
                || !Validate::isGenericName($send24_title)) {
                $output .= $this->displayError($this->l('Invalid title value'));
                $error = true;
            }
            // Check key.
            if (!$send24_consumer_key
                || empty($send24_consumer_key)
                || !Validate::isGenericName($send24_consumer_key)) {
                $output .= $this->displayError($this->l('Invalid consumer key'));
                $error = true;
            }
            // Check secret.
            if (!$send24_consumer_secret
                || empty($send24_consumer_secret)
                || !Validate::isGenericName($send24_consumer_secret)) {
                $output .= $this->displayError($this->l('Invalid consumer secret'));
                $error = true;
            }
            // Check start work.
            if (!$start_work_express
                || empty($start_work_express)
                || !Validate::isGenericName($start_work_express)) {
                $output .= $this->displayError($this->l('Invalid start work time'));
                $error = true;
            }
            // Check end work.
            if (!$end_work_express
                || empty($end_work_express)
                || !Validate::isGenericName($end_work_express)) {
                $output .= $this->displayError($this->l('Invalid end work time'));
                $error = true;
            }
            // Check keys authorization send24.com
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://send24.com/wc-api/v3/get_service_area/" . $this->postcode);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_USERPWD, $send24_consumer_key . ":" . $send24_consumer_secret);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                "Content-Type: application/json",
            ));
            $zip_area = curl_exec($ch);
            $zip = Tools::jsonDecode($zip_area, true);
            if (!empty($zip['errors'])) {
                $output .= $this->displayError($this->l('Invalid key authorization'));
            }

            if ($error == false) {
                Configuration::updateValue('send24_title', $send24_title);
                Configuration::updateValue('send24_consumer_key', $send24_consumer_key);
                Configuration::updateValue('send24_consumer_secret', $send24_consumer_secret);
                Configuration::updateValue('start_work_express', $start_work_express);
                Configuration::updateValue('end_work_express', $end_work_express);
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }
        return $output . $this->displayForm();
    }

    public function displayForm()
    {
        // Get default language
        $default_lang = (int) Configuration::get('PS_LANG_DEFAULT');
        $options = array(
            array(
                'id_option' => '00:00',
                'name' => '00:00',
            ),
            array(
                'id_option' => '00:30',
                'name' => '00:30',
            ),
            array(
                'id_option' => '01:00',
                'name' => '01:00',
            ),
            array(
                'id_option' => '01:30',
                'name' => '01:30',
            ),
            array(
                'id_option' => '02:00',
                'name' => '02:00',
            ),
            array(
                'id_option' => '02:30',
                'name' => '02:30',
            ),
            array(
                'id_option' => '03:00',
                'name' => '03:00',
            ),
            array(
                'id_option' => '03:30',
                'name' => '03:30',
            ),
            array(
                'id_option' => '04:00',
                'name' => '04:00',
            ),
            array(
                'id_option' => '04:30',
                'name' => '04:30',
            ),
            array(
                'id_option' => '05:00',
                'name' => '05:00',
            ),
            array(
                'id_option' => '05:30',
                'name' => '05:30',
            ),
            array(
                'id_option' => '06:00',
                'name' => '06:00',
            ),
            array(
                'id_option' => '06:30',
                'name' => '06:30',
            ),
            array(
                'id_option' => '07:00',
                'name' => '07:00',
            ),
            array(
                'id_option' => '07:30',
                'name' => '07:30',
            ),
            array(
                'id_option' => '08:00',
                'name' => '08:00',
            ),
            array(
                'id_option' => '08:30',
                'name' => '08:30',
            ),
            array(
                'id_option' => '09:00',
                'name' => '09:00',
            ),
            array(
                'id_option' => '09:30',
                'name' => '09:30',
            ),
            array(
                'id_option' => '10:00',
                'name' => '10:00',
            ),
            array(
                'id_option' => '10:30',
                'name' => '10:30',
            ),
            array(
                'id_option' => '11:00',
                'name' => '11:00',
            ),
            array(
                'id_option' => '11:30',
                'name' => '11:30',
            ),
            array(
                'id_option' => '12:00',
                'name' => '12:00',
            ),
            array(
                'id_option' => '12:30',
                'name' => '12:30',
            ),
            array(
                'id_option' => '13:00',
                'name' => '13:00',
            ),
            array(
                'id_option' => '13:30',
                'name' => '13:30',
            ),
            array(
                'id_option' => '14:00',
                'name' => '14:00',
            ),
            array(
                'id_option' => '14:30',
                'name' => '14:30',
            ),
            array(
                'id_option' => '15:00',
                'name' => '15:00',
            ),
            array(
                'id_option' => '15:30',
                'name' => '15:30',
            ),
            array(
                'id_option' => '16:00',
                'name' => '16:00',
            ),
            array(
                'id_option' => '16:30',
                'name' => '16:30',
            ),
            array(
                'id_option' => '17:00',
                'name' => '17:00',
            ),
            array(
                'id_option' => '17:30',
                'name' => '17:30',
            ),
            array(
                'id_option' => '18:00',
                'name' => '18:00',
            ),
            array(
                'id_option' => '18:30',
                'name' => '18:30',
            ),
            array(
                'id_option' => '19:00',
                'name' => '19:00',
            ),
            array(
                'id_option' => '19:30',
                'name' => '19:30',
            ),
            array(
                'id_option' => '20:00',
                'name' => '20:00',
            ),
            array(
                'id_option' => '20:30',
                'name' => '20:30',
            ),
            array(
                'id_option' => '21:00',
                'name' => '21:00',
            ),
            array(
                'id_option' => '21:30',
                'name' => '21:30',
            ),
            array(
                'id_option' => '22:00',
                'name' => '22:00',
            ),
            array(
                'id_option' => '22:30',
                'name' => '22:30',
            ),
            array(
                'id_option' => '23:00',
                'name' => '23:00',
            ),
            array(
                'id_option' => '23:30',
                'name' => '23:30',
            ),
        );

        $fields_form = array();
        // Init Fields form array
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('Settings'),
            ),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => $this->l('Title'),
                    'name' => 'send24_title',
                    'size' => 20,
                    'required' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Send24 Consumer Key'),
                    'name' => 'send24_consumer_key',
                    'size' => 20,
                    'required' => true,
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Send24 Consumer Secret'),
                    'name' => 'send24_consumer_secret',
                    'size' => 20,
                    'required' => true,
                ),
                array(
                    'type' => 'select',
                    'label' => $this->l('Start time work Express:'),
                    'desc' => $this->l('Please choose start time work Express.'),
                    'name' => 'start_work_express',
                    'required' => true,
                    'options' => array(
                        'query' => $options,
                        'id' => 'id_option',
                        'name' => 'name',
                    ),
                ),
                array(
                    'type' => 'select',
                    'label' => $this->l('End time work Express:'),
                    'desc' => $this->l('Please choose end time work Express.'),
                    'name' => 'end_work_express',
                    'required' => true,
                    'options' => array(
                        'query' => $options,
                        'id' => 'id_option',
                        'name' => 'name',
                    ),
                ),
            ),
            'submit' => array(
                'title' => $this->l('Save'),
                'class' => 'button',
            ),
        );

        $helper = new HelperForm();

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        // Language
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;

        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->show_toolbar = true; // false -> remove toolbar
        $helper->toolbar_scroll = true; // yes - > Toolbar is always visible on the top of the screen.
        $helper->submit_action = 'submit' . $this->name;
        $helper->toolbar_btn = array(
            'save' => array(
                'desc' => $this->l('Save'),
                'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&save' . $this->name .
                '&token=' . Tools::getAdminTokenLite('AdminModules'),
            ),
            'back' => array(
                'href' => AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list'),
            ),
        );

        // Load current value
        $helper->fields_value['send24_title'] = Configuration::get('send24_title');
        if (empty($helper->fields_value['send24_title'])) {
            $helper->fields_value['send24_title'] = 'Send24 Shipping';
        }
        $helper->fields_value['send24_consumer_key'] = Configuration::get('send24_consumer_key');
        $helper->fields_value['send24_consumer_secret'] = Configuration::get('send24_consumer_secret');
        $helper->fields_value['start_work_express'] = Configuration::get('start_work_express');
        $helper->fields_value['end_work_express'] = Configuration::get('end_work_express');

        return $helper->generateForm($fields_form);
    }
}
