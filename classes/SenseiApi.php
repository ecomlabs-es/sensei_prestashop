<?php
/**
 * Cliente mínimo para la API v1 de SendSei Pro.
 *
 * @author ecomlabs
 */
class SenseiApi
{
    const BASE = 'https://app.sendseipro.com';

    /** @var string */
    private $token;

    public function __construct($token)
    {
        $this->token = trim((string) $token);
    }

    public function quote(array $payload)
    {
        return $this->call('POST', '/api/v1/quotes/', $payload);
    }

    public function createShipment(array $payload)
    {
        return $this->call('POST', '/api/v1/shipments/', $payload);
    }

    public function createPickup(array $payload)
    {
        return $this->call('POST', '/api/v1/pickups/', $payload);
    }

    public function cancelShipment($uuid, $reason = '')
    {
        return $this->call('POST', '/api/v1/shipments/' . rawurlencode($uuid) . '/cancel/', $reason ? ['reason' => $reason] : new stdClass());
    }

    /** Devuelve el binario PDF de la etiqueta. */
    public function label($uuid)
    {
        return $this->call('GET', '/api/v1/shipments/' . rawurlencode($uuid) . '/label/', null, true);
    }

    public function tracking($trackingNumber)
    {
        return $this->call('GET', '/api/v1/tracking/' . rawurlencode($trackingNumber) . '/');
    }

    /** $path viene de `delivery_points_url` de la cotización. */
    public function deliveryPoints($path, array $query)
    {
        return $this->call('GET', $path . '?' . http_build_query($query));
    }

    /**
     * @return array|string decoded JSON (o binario si $raw)
     *
     * @throws Exception con mensaje legible
     */
    private function call($method, $path, $body = null, $raw = false)
    {
        if ($this->token === '') {
            throw new Exception('Sensei API Token is missing (set it in the module settings).');
        }
        $ch = curl_init(self::BASE . $path);
        $headers = ['X-API-Token: ' . $this->token, 'Accept: ' . ($raw ? '*/*' : 'application/json')];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($res === false) {
            throw new Exception('Connection error with Sensei: ' . $err);
        }
        if ($raw) {
            if ($code >= 400) {
                throw new Exception('Sensei HTTP ' . $code . ': ' . self::flatten(json_decode($res, true)));
            }

            return $res;
        }
        $json = json_decode($res, true);
        if ($code >= 400) {
            throw new Exception('Sensei HTTP ' . $code . ': ' . self::flatten($json ?: $res));
        }

        return $json;
    }

    /** Convierte una respuesta de error (array anidado) en texto. */
    public static function flatten($data)
    {
        if (is_string($data)) {
            return $data;
        }
        if (!is_array($data)) {
            return (string) json_encode($data);
        }
        $out = [];
        foreach ($data as $k => $v) {
            $v = is_array($v) ? self::flatten($v) : (string) $v;
            $out[] = is_int($k) ? $v : $k . ': ' . $v;
        }

        return implode(' | ', $out);
    }
}
