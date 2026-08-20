<?php
/**
 * Pestaña "Sensei" del backoffice: listado de envíos + endpoints ajax usados desde el pedido.
 *
 * @author ecomlabs
 */
require_once _PS_MODULE_DIR_ . 'sensei/classes/SenseiApi.php';

class AdminSenseiController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'sensei_shipment';
        $this->identifier = 'id_sensei_shipment';
        $this->list_no_link = true;
        $this->_defaultOrderBy = 'id_sensei_shipment';
        $this->_defaultOrderWay = 'DESC';
        $this->allow_export = true;
        parent::__construct();

        $this->fields_list = [
            'id_sensei_shipment' => ['title' => 'ID', 'class' => 'fixed-width-xs'],
            'id_order' => ['title' => $this->module->l('Order', 'adminsenseicontroller'), 'callback' => 'orderLink', 'class' => 'fixed-width-sm'],
            'tracking_number' => ['title' => 'Tracking'],
            'courier' => ['title' => 'Courier'],
            'service' => ['title' => $this->module->l('Service', 'adminsenseicontroller')],
            'total' => ['title' => $this->module->l('Cost', 'adminsenseicontroller'), 'type' => 'price'],
            'cod_amount' => ['title' => $this->module->l('COD', 'adminsenseicontroller'), 'type' => 'price'],
            'pickup_code' => ['title' => $this->module->l('Pickup', 'adminsenseicontroller')],
            'pickup_date' => ['title' => $this->module->l('Pickup date', 'adminsenseicontroller'), 'type' => 'date'],
            'status' => ['title' => $this->module->l('Status', 'adminsenseicontroller')],
            'uuid' => ['title' => $this->module->l('Label', 'adminsenseicontroller'), 'callback' => 'labelLink', 'search' => false, 'orderby' => false],
            'date_add' => ['title' => $this->module->l('Created', 'adminsenseicontroller'), 'type' => 'datetime'],
        ];
    }

    public function orderLink($id)
    {
        return '<a href="' . $this->context->link->getAdminLink('AdminOrders', true, ['orderId' => (int) $id]) . '">#' . (int) $id . '</a>';
    }

    public function labelLink($uuid, $row)
    {
        if ($row['status'] === 'cancelled') {
            return '-';
        }

        return '<a class="btn btn-default btn-xs" target="_blank" href="' . $this->labelUrl($uuid) . '"><i class="icon-print"></i> PDF</a>';
    }

    private function labelUrl($uuid)
    {
        return $this->context->link->getAdminLink('AdminSensei') . '&action=label&uuid=' . urlencode($uuid);
    }

    public function initPageHeaderToolbar()
    {
        $this->page_header_toolbar_btn['config'] = [
            'href' => $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => 'sensei']),
            'desc' => $this->module->l('Settings', 'adminsenseicontroller'),
            'icon' => 'process-icon-cogs',
        ];
        parent::initPageHeaderToolbar();
    }

    /** UUID del envío validado (evita inyección en cabeceras/URL). */
    private function uuidParam()
    {
        $uuid = (string) Tools::getValue('uuid');
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $uuid)) {
            throw new Exception('Invalid shipment uuid.');
        }

        return $uuid;
    }

    /** Comprueba que el empleado puede editar (acciones que crean/cancelan envíos). */
    private function requireEdit()
    {
        if (!$this->access('edit')) {
            throw new Exception($this->module->l('You do not have permission to do this.', 'adminsenseicontroller'));
        }
    }

    public function initContent()
    {
        if (Tools::getValue('action') === 'label' && Tools::getValue('uuid')) {
            $uuid = $this->uuidParam();
            $pdf = $this->api()->label($uuid);
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="sensei-' . $uuid . '.pdf"');
            echo $pdf;
            exit;
        }
        parent::initContent();
    }

    private function api()
    {
        return new SenseiApi(Configuration::get('SENSEI_TOKEN'));
    }

    /* ---------------- AJAX ---------------- */

    /** Envuelve una acción ajax devolviendo JSON {ok, ...} o {ok:false, error}. */
    private function respond(callable $fn)
    {
        try {
            $data = $fn();
            $this->ajaxRender(json_encode($data + ['ok' => true]));
        } catch (Exception $e) {
            $this->ajaxRender(json_encode(['ok' => false, 'error' => $e->getMessage()]));
        }
        exit;
    }

    /** Paquetes del formulario -> formato API (quote o shipment). */
    private function packages($forShipment, $content = '')
    {
        $rows = Tools::getValue('packages');
        if (!is_array($rows) || !$rows) {
            throw new Exception($this->module->l('Add at least one package.', 'adminsenseicontroller'));
        }
        $out = [];
        foreach ($rows as $r) {
            $p = [
                'length_cm' => (string) (float) ($r['l'] ?? 0),
                'width_cm' => (string) (float) ($r['w'] ?? 0),
                'height_cm' => (string) (float) ($r['h'] ?? 0),
            ];
            $weight = (float) ($r['weight'] ?? 0);
            if ($weight <= 0) {
                throw new Exception($this->module->l('Package weight must be greater than 0.', 'adminsenseicontroller'));
            }
            if ($forShipment) {
                $p['weight_kg'] = (string) $weight;
                $p['content_description'] = $content;
                $p['is_fragile'] = false;
            } else {
                $p['weight'] = (string) $weight;
            }
            $out[] = $p;
        }

        return $out;
    }

    public function ajaxProcessQuote()
    {
        $this->respond(function () {
            $order = new Order((int) Tools::getValue('id_order'));
            $dest = Sensei::destinationAddress($order);
            $payload = [
                'postal_code_from' => Configuration::get('SENSEI_ORIGIN_CP'),
                'country_from' => Configuration::get('SENSEI_ORIGIN_COUNTRY') ?: 'ES',
                'postal_code_to' => $dest['postal_code'],
                'country_to' => $dest['country'],
                'packages' => $this->packages(false),
            ];
            if ((float) Tools::getValue('insured_amount') > 0) {
                $payload['insured_amount'] = (string) (float) Tools::getValue('insured_amount');
            }
            $res = $this->api()->quote($payload);
            $allowed = array_filter(array_map('trim', explode(',', Tools::strtolower((string) Configuration::get('SENSEI_ALLOWED_COURIERS')))));
            $maxHours = (float) Configuration::get('SENSEI_MAX_HOURS');
            // Solo entrega a domicilio: fuera servicios que requieren punto de entrega/recogida.
            $rates = array_values(array_filter($res['results'] ?? [], function ($r) use ($allowed, $maxHours) {
                if ($allowed && !in_array(Tools::strtolower(trim((string) $r['courier'])), $allowed, true)) {
                    return false;
                }
                $hours = Sensei::deliveryHours((string) ($r['delivery_time'] ?? ''));
                if ($maxHours && $hours && $hours > $maxHours) {
                    return false;
                }
                return empty($r['requires_delivery_point']) && empty($r['requires_pickup_point'])
                    && !preg_match('/punto|locker|oficina|access point|2shop|pudo|service point/i', $r['service']);
            }));

            if ($rates) {
                Sensei::changeState($order, 'SENSEI_STATE_QUOTE');
            }

            return ['rates' => $rates, 'postal_code' => $dest['postal_code'], 'city' => $dest['city']];
        });
    }

    public function ajaxProcessPoints()
    {
        $this->respond(function () {
            $path = (string) Tools::getValue('path');
            if (!preg_match('#^/api/v1/[a-z0-9-]+/[a-z0-9-]+/$#', $path)) {
                throw new Exception($this->module->l('Invalid points path.', 'adminsenseicontroller'));
            }
            $q = ['postal_code' => Tools::getValue('postal_code')];
            if (Tools::getValue('city')) {
                $q['city'] = Tools::getValue('city');
            }
            $res = $this->api()->deliveryPoints($path, $q);
            $points = [];
            foreach ($res['results'] ?? [] as $p) {
                $points[] = [
                    'code' => $p['code'] ?? $p['pudo_id'] ?? $p['location_id'] ?? '',
                    'label' => trim(($p['name'] ?? '') . ' - ' . ($p['address'] ?? $p['address_line'] ?? '') . ', ' . ($p['city'] ?? '')),
                ];
            }

            return ['points' => $points];
        });
    }

    public function ajaxProcessShip()
    {
        $this->respond(function () {
            $this->requireEdit();
            $order = new Order((int) Tools::getValue('id_order'));
            if (!Validate::isLoadedObject($order)) {
                throw new Exception($this->module->l('Order not found.', 'adminsenseicontroller'));
            }
            if (!Tools::getValue('force')) {
                foreach (Sensei::getShipments((int) $order->id) as $s) {
                    if (!in_array($s['status'], ['cancelled', 'cancelado'], true)) {
                        return ['ok' => false, 'duplicate' => true, 'error' => $this->module->l('This order already has an active shipment (', 'adminsenseicontroller') . $s['tracking_number'] . ').'];
                    }
                }
            }
            $serviceId = (int) Tools::getValue('service_id');
            if (!$serviceId) {
                throw new Exception($this->module->l('Select a service from the quote.', 'adminsenseicontroller'));
            }
            $content = trim(Configuration::get('SENSEI_CONTENT') . ' ' . $order->reference);
            $payload = [
                'origin' => Sensei::originAddress(),
                'destination' => Sensei::destinationAddress($order),
                'courier_service_id' => $serviceId,
                'courier_id' => (int) Tools::getValue('courier_id') ?: null,
                'packages' => $this->packages(true, $content),
                'label_format' => 'PDF',
                'customer_reference' => Tools::substr('#' . $order->reference, 0, 40),
                'cod_enabled' => false,
                'cod_amount' => null,
            ];
            if (Tools::getValue('cod_enabled')) {
                $amount = (float) Tools::getValue('cod_amount');
                if ($amount <= 0) {
                    throw new Exception($this->module->l('Enter the cash on delivery amount.', 'adminsenseicontroller'));
                }
                $payload['cod_enabled'] = true;
                $payload['cod_amount'] = number_format($amount, 2, '.', '');
            }
            if ((float) Tools::getValue('insured_amount') > 0) {
                $payload['insured_amount'] = number_format((float) Tools::getValue('insured_amount'), 2, '.', '');
            }
            if (Tools::getValue('delivery_point_code')) {
                $payload['delivery_point_code'] = Tools::getValue('delivery_point_code');
            }
            if (Tools::getValue('origin_pickup_point_code')) {
                $payload['origin_pickup_point_code'] = Tools::getValue('origin_pickup_point_code');
            }

            $api = $this->api();
            $ship = $api->createShipment($payload);

            $row = [
                'id_order' => (int) $order->id,
                'uuid' => pSQL($ship['uuid']),
                'tracking_number' => pSQL((string) ($ship['tracking_number'] ?? '')),
                'courier' => pSQL((string) Tools::getValue('courier_name')),
                'service' => pSQL((string) Tools::getValue('service_name')),
                'total' => (float) Tools::getValue('service_total'),
                'cod_amount' => $payload['cod_enabled'] ? (float) $payload['cod_amount'] : null,
                'status' => pSQL((string) ($ship['status'] ?? 'created')),
                'date_add' => date('Y-m-d H:i:s'),
            ];

            // Recogida (opcional, misma acción)
            $pickupMsg = '';
            if (Tools::getValue('pickup_enabled')) {
                try {
                    $pk = $api->createPickup([
                        'scheduled_date' => Tools::getValue('pickup_date'),
                        'scheduled_time_from' => Tools::getValue('pickup_from'),
                        'scheduled_time_to' => Tools::getValue('pickup_to'),
                        'shipment_uuids' => [$ship['uuid']],
                        'notes' => Tools::substr((string) Tools::getValue('pickup_notes'), 0, 500),
                    ]);
                    $first = $pk['pickups'][0] ?? [];
                    $row['pickup_code'] = pSQL((string) ($first['pickup_code'] ?? ''));
                    $row['pickup_date'] = pSQL((string) ($first['scheduled_date'] ?? Tools::getValue('pickup_date')));
                    $pickupMsg = (string) ($first['message'] ?? '');
                    if (empty($first['success'])) {
                        $pickupMsg = $this->module->l('Shipment created, but the pickup failed: ', 'adminsenseicontroller') . $pickupMsg;
                    }
                } catch (Exception $e) {
                    $pickupMsg = $this->module->l('Shipment created, but the pickup failed: ', 'adminsenseicontroller') . $e->getMessage();
                }
            }

            Db::getInstance()->insert('sensei_shipment', $row, true);
            Sensei::changeState($order, 'SENSEI_STATE_SHIP');

            if (Configuration::get('SENSEI_SET_TRACKING') && !empty($ship['tracking_number'])) {
                $order->shipping_number = $ship['tracking_number'];
                $order->update();
                Db::getInstance()->update('order_carrier', ['tracking_number' => pSQL($ship['tracking_number'])], 'id_order=' . (int) $order->id);
            }

            $this->context->smarty->assign([
                'sensei_ajax' => $this->context->link->getAdminLink('AdminSensei'),
                'sensei_delivered' => Sensei::DELIVERED,
                's' => Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'sensei_shipment` WHERE uuid="' . pSQL($ship['uuid']) . '"'),
            ]);

            return [
                'uuid' => $ship['uuid'],
                'tracking_number' => $ship['tracking_number'] ?? '',
                'label_url' => $this->labelUrl($ship['uuid']),
                'pickup_code' => $row['pickup_code'] ?? '',
                'pickup_message' => $pickupMsg,
                'row_html' => $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'sensei/views/templates/hook/shipment_row.tpl'),
            ];
        });
    }

    public function ajaxProcessCancel()
    {
        $this->respond(function () {
            $this->requireEdit();
            $uuid = $this->uuidParam();
            if (!Db::getInstance()->getValue('SELECT id_sensei_shipment FROM `' . _DB_PREFIX_ . 'sensei_shipment` WHERE uuid="' . pSQL($uuid) . '"')) {
                throw new Exception($this->module->l('Shipment not found.', 'adminsenseicontroller'));
            }
            $res = $this->api()->cancelShipment($uuid, $this->module->l('Cancelled from PrestaShop', 'adminsenseicontroller'));
            Db::getInstance()->update('sensei_shipment', ['status' => 'cancelled'], 'uuid="' . pSQL($uuid) . '"');

            return ['message' => $res['message'] ?? $this->module->l('Shipment cancelled.', 'adminsenseicontroller')];
        });
    }

    public function ajaxProcessTracking()
    {
        $this->respond(function () {
            $row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'sensei_shipment` WHERE tracking_number="' . pSQL(Tools::getValue('tracking_number')) . '"');
            if (!$row) {
                throw new Exception($this->module->l('Shipment not found.', 'adminsenseicontroller'));
            }
            $res = Sensei::syncShipment($row);
            $status = (string) ($res['current_status'] ?? '');
            $history = [];
            foreach ($res['status_history'] ?? [] as $h) {
                $history[] = ($h['timestamp'] ?? '') . ' - ' . ($h['status'] ?? '') . ' ' . ($h['provider_status_description'] ?? $h['note'] ?? '');
            }

            return ['status' => $status, 'history' => $history];
        });
    }
}
