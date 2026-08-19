<?php
/**
 * Cron: sincroniza el estado de los envíos abiertos y marca el pedido como entregado.
 * URL: /index.php?fc=module&module=sensei&controller=cron&token=XXX
 *
 * @author ecomlabs
 */
class SenseiCronModuleFrontController extends ModuleFrontController
{
    public function init()
    {
        $token = (string) Configuration::get('SENSEI_CRON_TOKEN');
        if ($token === '' || !hash_equals($token, (string) Tools::getValue('token'))) {
            header('HTTP/1.1 403 Forbidden');
            exit('Invalid token');
        }
        $final = array_map(function ($s) { return "'" . pSQL($s) . "'"; }, Sensei::FINAL);
        $rows = Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'sensei_shipment`
            WHERE tracking_number <> "" AND (status IS NULL OR status NOT IN (' . implode(',', $final) . '))
            AND date_add > DATE_SUB(NOW(), INTERVAL 60 DAY)') ?: [];
        $n = 0;
        foreach ($rows as $r) {
            try {
                $res = Sensei::syncShipment($r);
                echo $r['tracking_number'] . ': ' . ($res['current_status'] ?? '?') . "\n";
                ++$n;
            } catch (Exception $e) {
                echo $r['tracking_number'] . ': ERROR ' . $e->getMessage() . "\n";
            }
        }
        exit("OK $n shipments synced\n");
    }
}
