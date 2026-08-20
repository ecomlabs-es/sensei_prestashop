<?php
/**
 * Sensei - Integración con SendSei Pro para PrestaShop 8.
 *
 * @author ecomlabs
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/SenseiApi.php';

class Sensei extends Module
{
    const CONFIG = [
        'SENSEI_TOKEN' => '',
        'SENSEI_ORIGIN_NAME' => '',
        'SENSEI_ORIGIN_COMPANY' => '',
        'SENSEI_ORIGIN_EMAIL' => '',
        'SENSEI_ORIGIN_PHONE' => '',
        'SENSEI_ORIGIN_ADDRESS' => '',
        'SENSEI_ORIGIN_NUMBER' => '',
        'SENSEI_ORIGIN_ADDRESS2' => '',
        'SENSEI_ORIGIN_CP' => '',
        'SENSEI_ORIGIN_CITY' => '',
        'SENSEI_ORIGIN_COUNTRY' => 'ES',
        'SENSEI_DEF_WEIGHT' => '1',
        'SENSEI_DEF_L' => '30',
        'SENSEI_DEF_W' => '20',
        'SENSEI_DEF_H' => '15',
        'SENSEI_COD_MODULES' => 'codfee,ps_cashondelivery',
        'SENSEI_ALLOWED_COURIERS' => '',
        'SENSEI_MAX_HOURS' => '',
        'SENSEI_PICKUP_FROM' => '09:00',
        'SENSEI_PICKUP_TO' => '14:00',
        'SENSEI_CONTENT' => 'Pedido',
        'SENSEI_SET_TRACKING' => '1',
        'SENSEI_STATE_QUOTE' => '',
        'SENSEI_STATE_SHIP' => '',
        'SENSEI_STATE_DELIVERED' => '',
        'SENSEI_CRON_TOKEN' => '',
    ];
    // Catálogo de la API de SendSei; el nombre debe coincidir con el `courier` de las cotizaciones.
    const COURIERS = ['correos' => 'Correos', 'correos_express' => 'Correos Express', 'dhl_parcel' => 'DHL Parcel',
        'inpost' => 'InPost', 'seur' => 'Seur', 'ups' => 'UPS', 'zeleris' => 'Zeleris'];
    const DELIVERED = ['entregado', 'entregado_pudo', 'delivered'];
    const FINAL = ['entregado', 'entregado_pudo', 'delivered', 'devuelto', 'cancelado', 'cancelled', 'destruido', 'reexpedido',
        'siniestrado_sin_informacion', 'siniestrado_rotura', 'siniestrado_robo', 'siniestrado_perdida'];

    public function __construct()
    {
        $this->name = 'sensei';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.0';
        $this->author = 'ecomlabs';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        parent::__construct();
        $this->displayName = 'Sensei';
        $this->description = $this->l('Quote, create shipments and schedule pickups with SendSei Pro from the order page.');
    }

    public function install()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'sensei_shipment` (
            `id_sensei_shipment` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_order` INT UNSIGNED NOT NULL,
            `uuid` VARCHAR(40) NOT NULL,
            `tracking_number` VARCHAR(64) DEFAULT NULL,
            `courier` VARCHAR(64) DEFAULT NULL,
            `service` VARCHAR(128) DEFAULT NULL,
            `total` DECIMAL(10,2) DEFAULT NULL,
            `cod_amount` DECIMAL(10,2) DEFAULT NULL,
            `pickup_code` VARCHAR(64) DEFAULT NULL,
            `pickup_date` DATE DEFAULT NULL,
            `status` VARCHAR(32) DEFAULT NULL,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_sensei_shipment`),
            KEY `id_order` (`id_order`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';

        if (!parent::install() || !Db::getInstance()->execute($sql)
            || !$this->registerHook('displayAdminOrderMain')
            || !$this->registerHook('actionAdminControllerSetMedia')
            || !$this->installTab()) {
            return false;
        }
        foreach (self::CONFIG as $k => $v) {
            Configuration::updateValue($k, $v);
        }
        Configuration::updateValue('SENSEI_STATE_QUOTE', (int) Configuration::get('PS_OS_PREPARATION'));
        Configuration::updateValue('SENSEI_STATE_SHIP', (int) Configuration::get('PS_OS_SHIPPING'));
        Configuration::updateValue('SENSEI_STATE_DELIVERED', (int) Configuration::get('PS_OS_DELIVERED'));
        Configuration::updateValue('SENSEI_CRON_TOKEN', Tools::passwdGen(24));
        Configuration::updateValue('SENSEI_ORIGIN_NAME', Configuration::get('PS_SHOP_NAME'));
        Configuration::updateValue('SENSEI_ORIGIN_EMAIL', Configuration::get('PS_SHOP_EMAIL'));
        Configuration::updateValue('SENSEI_ORIGIN_PHONE', Configuration::get('PS_SHOP_PHONE'));
        Configuration::updateValue('SENSEI_ORIGIN_ADDRESS', Configuration::get('PS_SHOP_ADDR1'));
        Configuration::updateValue('SENSEI_ORIGIN_ADDRESS2', Configuration::get('PS_SHOP_ADDR2'));
        Configuration::updateValue('SENSEI_ORIGIN_CP', Configuration::get('PS_SHOP_CODE'));
        Configuration::updateValue('SENSEI_ORIGIN_CITY', Configuration::get('PS_SHOP_CITY'));

        return true;
    }

    public function uninstall()
    {
        foreach (array_keys(self::CONFIG) as $k) {
            Configuration::deleteByName($k);
        }
        $id = (int) Tab::getIdFromClassName('AdminSensei');
        if ($id) {
            (new Tab($id))->delete();
        }
        // ponytail: la tabla de envíos se conserva (histórico). Bórrala a mano si hace falta.
        return parent::uninstall();
    }

    private function installTab()
    {
        $tab = new Tab();
        $tab->class_name = 'AdminSensei';
        $tab->module = $this->name;
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminParentOrders');
        $tab->icon = 'local_shipping';
        $tab->active = 1;
        foreach (Language::getLanguages(false) as $l) {
            $tab->name[$l['id_lang']] = 'Sensei';
        }

        return (bool) $tab->add();
    }

    /* ---------------- Configuración ---------------- */

    public function getContent()
    {
        $out = '';
        if (Tools::isSubmit('submitSensei')) {
            foreach (array_keys(self::CONFIG) as $k) {
                if ($k === 'SENSEI_CRON_TOKEN') {
                    continue; // no está en el formulario
                }
                if ($k === 'SENSEI_ALLOWED_COURIERS') { // checkboxes; todos marcados se guarda vacío (= todos, incluidos futuros)
                    $sel = [];
                    foreach (self::COURIERS as $slug => $name) {
                        if (Tools::getValue('SENSEI_COURIER_' . $slug)) {
                            $sel[] = $name;
                        }
                    }
                    Configuration::updateValue($k, count($sel) === count(self::COURIERS) ? '' : implode(',', $sel));
                    continue;
                }
                Configuration::updateValue($k, trim((string) Tools::getValue($k)));
            }
            $out .= $this->displayConfirmation($this->l('Settings saved.'));
        }
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->submit_action = 'submitSensei';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        foreach (array_keys(self::CONFIG) as $k) {
            $helper->fields_value[$k] = Configuration::get($k);
        }
        $allowed = array_filter(array_map('trim', explode(',', Tools::strtolower((string) Configuration::get('SENSEI_ALLOWED_COURIERS')))));
        foreach (self::COURIERS as $slug => $name) {
            $helper->fields_value['SENSEI_COURIER_' . $slug] = !$allowed || in_array(Tools::strtolower($name), $allowed, true);
        }
        $t = function ($label, $name, $desc = '', $required = false) {
            return ['type' => 'text', 'label' => $label, 'name' => $name, 'desc' => $desc, 'required' => $required];
        };
        $states = array_merge([['id_order_state' => '', 'name' => $this->l('-- Do not change --')]], OrderState::getOrderStates((int) $this->context->language->id));
        if (!Configuration::get('SENSEI_CRON_TOKEN')) { // instalaciones/actualizaciones sin token generado
            Configuration::updateValue('SENSEI_CRON_TOKEN', Tools::passwdGen(24));
        }
        $cron = $this->context->link->getModuleLink('sensei', 'cron', ['token' => Configuration::get('SENSEI_CRON_TOKEN')]);
        $form = ['form' => [
            'legend' => ['title' => 'Sensei - SendSei Pro', 'icon' => 'icon-truck'],
            'input' => [
                $t('API Token', 'SENSEI_TOKEN', $this->l('Key from https://app.sendseipro.com'), true),
                $t($this->l('Sender: name'), 'SENSEI_ORIGIN_NAME', '', true),
                $t($this->l('Sender: company'), 'SENSEI_ORIGIN_COMPANY'),
                $t($this->l('Sender: email'), 'SENSEI_ORIGIN_EMAIL', '', true),
                $t($this->l('Sender: phone'), 'SENSEI_ORIGIN_PHONE', '', true),
                $t($this->l('Sender: street'), 'SENSEI_ORIGIN_ADDRESS', $this->l('Without the number'), true),
                $t($this->l('Sender: number'), 'SENSEI_ORIGIN_NUMBER', '', true),
                $t($this->l('Sender: floor / door'), 'SENSEI_ORIGIN_ADDRESS2'),
                $t($this->l('Sender: postal code'), 'SENSEI_ORIGIN_CP', '', true),
                $t($this->l('Sender: city'), 'SENSEI_ORIGIN_CITY', '', true),
                $t($this->l('Sender: country (ISO2)'), 'SENSEI_ORIGIN_COUNTRY', '', true),
                $t($this->l('Default weight (kg)'), 'SENSEI_DEF_WEIGHT', $this->l('Used when products have no weight')),
                $t($this->l('Default length (cm)'), 'SENSEI_DEF_L'),
                $t($this->l('Default width (cm)'), 'SENSEI_DEF_W'),
                $t($this->l('Default height (cm)'), 'SENSEI_DEF_H'),
                $t($this->l('Cash on delivery payment modules'), 'SENSEI_COD_MODULES', $this->l('Comma separated. If the order was paid with one of them, cash on delivery is enabled automatically.')),
                ['type' => 'checkbox', 'label' => $this->l('Couriers: allowed'), 'name' => 'SENSEI_COURIER',
                    'desc' => $this->l('Unchecked couriers are hidden from quotes.'),
                    'values' => ['query' => array_map(function ($slug) {
                        return ['id' => $slug, 'name' => self::COURIERS[$slug]];
                    }, array_keys(self::COURIERS)), 'id' => 'id', 'name' => 'name']],
                $t($this->l('Couriers: max delivery time (hours)'), 'SENSEI_MAX_HOURS', $this->l('Hide services with a longer estimated delivery time. Services without an estimate are kept. Empty = no limit.')),
                $t($this->l('Pickup: time from'), 'SENSEI_PICKUP_FROM', 'HH:MM'),
                $t($this->l('Pickup: time to'), 'SENSEI_PICKUP_TO', 'HH:MM'),
                $t($this->l('Content description'), 'SENSEI_CONTENT', $this->l('The order reference is appended')),
                ['type' => 'select', 'label' => $this->l('Order status after quoting'), 'name' => 'SENSEI_STATE_QUOTE', 'desc' => $this->l('Empty = do not change'),
                    'options' => ['query' => $states, 'id' => 'id_order_state', 'name' => 'name']],
                ['type' => 'select', 'label' => $this->l('Order status after shipping'), 'name' => 'SENSEI_STATE_SHIP', 'desc' => $this->l('Empty = do not change'),
                    'options' => ['query' => $states, 'id' => 'id_order_state', 'name' => 'name']],
                ['type' => 'select', 'label' => $this->l('Order status when delivered'), 'name' => 'SENSEI_STATE_DELIVERED',
                    'desc' => $this->l('Applied when tracking is synced (on order view or via cron). Cron URL: ') . $cron,
                    'options' => ['query' => $states, 'id' => 'id_order_state', 'name' => 'name']],
                ['type' => 'switch', 'label' => $this->l('Save tracking number in the order'), 'name' => 'SENSEI_SET_TRACKING',
                    'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Yes')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
            ],
            'submit' => ['title' => $this->l('Save')],
        ]];

        return $out . $helper->generateForm([$form]);
    }

    /* ---------------- Hooks pedido ---------------- */

    public function hookActionAdminControllerSetMedia()
    {
        if (Tools::getValue('controller') === 'AdminOrders' && Tools::getValue('id_order')) {
            $this->context->controller->addJS($this->_path . 'views/js/sensei_order.js');
            $this->context->controller->addCSS($this->_path . 'views/css/sensei.css');
        }
    }

    public function hookDisplayAdminOrderMain($params)
    {
        $order = new Order((int) $params['id_order']);
        $address = new Address((int) $order->id_address_delivery);
        $country = new Country((int) $address->id_country);
        $customer = new Customer((int) $order->id_customer);

        $weight = 0.0;
        foreach ($order->getProducts() as $p) {
            $weight += (float) $p['product_weight'] * (int) $p['product_quantity'];
        }
        $codModules = array_map('trim', explode(',', (string) Configuration::get('SENSEI_COD_MODULES')));
        $isCod = in_array($order->module, $codModules, true);

        // Siguiente día laborable para la recogida
        $pickup = new DateTime('tomorrow');
        while ((int) $pickup->format('N') >= 6) {
            $pickup->modify('+1 day');
        }

        $this->context->smarty->assign([
            'sensei_ajax' => $this->context->link->getAdminLink('AdminSensei'),
            'sensei_img' => $this->_path . 'views/img/couriers/',
            'sensei_order' => $order,
            'sensei_dest' => [
                'name' => $address->firstname . ' ' . $address->lastname,
                'company' => $address->company,
                'address' => trim($address->address1 . ' ' . $address->address2),
                'cp' => $address->postcode,
                'city' => $address->city,
                'country' => $country->iso_code,
                'phone' => $address->phone_mobile ?: $address->phone,
                'email' => $customer->email,
            ],
            'sensei_weight' => $weight > 0 ? round($weight, 2) : (float) Configuration::get('SENSEI_DEF_WEIGHT'),
            'sensei_dims' => [Configuration::get('SENSEI_DEF_L'), Configuration::get('SENSEI_DEF_W'), Configuration::get('SENSEI_DEF_H')],
            'sensei_is_cod' => $isCod,
            'sensei_cod_amount' => round((float) $order->total_paid_tax_incl, 2),
            'sensei_pickup_date' => $pickup->format('Y-m-d'),
            'sensei_pickup_from' => Configuration::get('SENSEI_PICKUP_FROM'),
            'sensei_pickup_to' => Configuration::get('SENSEI_PICKUP_TO'),
            'sensei_shipments' => self::syncOrderShipments((int) $order->id),
            'sensei_has_token' => (bool) Configuration::get('SENSEI_TOKEN'),
            'sensei_delivered' => self::DELIVERED,
        ]);

        return $this->display(__FILE__, 'views/templates/hook/order_card.tpl');
    }

    /** Cambia el estado del pedido segun la clave de configuración (si está definida y es distinto al actual). */
    public static function changeState(Order $order, $configKey)
    {
        $idState = (int) Configuration::get($configKey);
        if (!$idState || (int) $order->current_state === $idState) {
            return;
        }
        $h = new OrderHistory();
        $h->id_order = (int) $order->id;
        $h->id_employee = (int) (Context::getContext()->employee->id ?? 0);
        $h->changeIdOrderState($idState, $order, true);
        $h->addWithemail();
    }

    /** Actualiza el estado de los envíos abiertos de un pedido contra la API y cambia el estado del pedido si se entregó. */
    public static function syncOrderShipments($idOrder)
    {
        foreach (self::getShipments($idOrder) as $s) {
            if ($s['tracking_number'] && !in_array($s['status'], self::FINAL, true)) {
                try {
                    self::syncShipment($s);
                } catch (Exception $e) {
                    // ponytail: fallo de red al sincronizar no debe romper la vista del pedido
                }
            }
        }

        return self::getShipments($idOrder);
    }

    /** @return array respuesta de tracking */
    public static function syncShipment(array $row)
    {
        $api = new SenseiApi(Configuration::get('SENSEI_TOKEN'));
        $res = $api->tracking($row['tracking_number']);
        $status = (string) ($res['current_status'] ?? '');
        if ($status && $status !== $row['status']) {
            Db::getInstance()->update('sensei_shipment', ['status' => pSQL($status)], 'id_sensei_shipment=' . (int) $row['id_sensei_shipment']);
        }
        if (in_array($status, self::DELIVERED, true)) {
            self::changeState(new Order((int) $row['id_order']), 'SENSEI_STATE_DELIVERED');
        }

        return $res;
    }

    public static function getShipments($idOrder)
    {
        return Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'sensei_shipment` WHERE id_order=' . (int) $idOrder . ' ORDER BY id_sensei_shipment DESC') ?: [];
    }

    /** Peor plazo en horas de un texto tipo "24/48h", "4 - 10 dias" o "24 horas"; 0 si no se puede saber. */
    public static function deliveryHours($s)
    {
        $s = Tools::strtolower($s);
        if (preg_match_all('/(\d+)\s*h/', $s, $m)) {
            return (float) max($m[1]);
        }
        if ((strpos($s, 'dia') !== false || strpos($s, 'día') !== false) && preg_match_all('/\d+/', $s, $m)) {
            return max($m[0]) * 24.0;
        }
        return 0.0;
    }

    /** Dirección de origen en formato API a partir de la configuración. */
    public static function originAddress()
    {
        return [
            'full_name' => Configuration::get('SENSEI_ORIGIN_NAME'),
            'company' => Configuration::get('SENSEI_ORIGIN_COMPANY'),
            'email' => Configuration::get('SENSEI_ORIGIN_EMAIL'),
            'phone_number' => Configuration::get('SENSEI_ORIGIN_PHONE'),
            'address' => Configuration::get('SENSEI_ORIGIN_ADDRESS'),
            'address_number' => Configuration::get('SENSEI_ORIGIN_NUMBER') ?: 'S/N',
            'address_2' => Configuration::get('SENSEI_ORIGIN_ADDRESS2'),
            'postal_code' => Configuration::get('SENSEI_ORIGIN_CP'),
            'city' => Configuration::get('SENSEI_ORIGIN_CITY'),
            'country' => Configuration::get('SENSEI_ORIGIN_COUNTRY') ?: 'ES',
        ];
    }

    /** Dirección de destino en formato API a partir del pedido. */
    public static function destinationAddress(Order $order)
    {
        $a = new Address((int) $order->id_address_delivery);
        $country = new Country((int) $a->id_country);
        $customer = new Customer((int) $order->id_customer);

        // ponytail: PrestaShop no separa calle y número; heurística sobre address1, resto a address_2.
        $street = trim($a->address1);
        $number = 'S/N';
        $extra = '';
        if (preg_match('/^(.*?\D)[,\s]+(?:n[ºo°]?\.?\s*)?(\d+[A-Za-z]?)\b[,\s]*(.*)$/u', $street, $m)) {
            $street = trim($m[1], " ,");
            $number = $m[2];
            $extra = trim($m[3], " ,");
        }

        return [
            'full_name' => trim($a->firstname . ' ' . $a->lastname),
            'company' => (string) $a->company,
            'email' => $customer->email,
            'phone_number' => $a->phone_mobile ?: $a->phone,
            'address' => $street,
            'address_number' => $number,
            'address_2' => trim($extra . ' ' . $a->address2),
            'postal_code' => $a->postcode,
            'city' => $a->city,
            'country' => $country->iso_code,
        ];
    }
}
