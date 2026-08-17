<?php
/**
 * Repro: SoapClient typemap from_xml/to_xml must accept Closure/callable (php-src ZVAL_COPY),
 * not only string function names. Today VM ctor throws LogicException on Closure options.
 */
declare(strict_types=1);

$resp = __DIR__.'/../fixtures/soap/book.response.xml';

if (!class_exists('SoapClient')) {
    echo "soap_unavailable\n";
    exit(0);
}

$from = static function (string $xml): string {
    if (preg_match('/<title[^>]*>([^<]*)</', $xml, $m)) {
        return 'MAPPED:'.$m[1];
    }

    return 'MAPPED';
};

try {
    $client = new SoapClient(null, [
        'location' => $resp,
        'uri' => 'urn:book',
        'trace' => 1,
        'typemap' => [[
            'type_ns' => 'urn:book',
            'type_name' => 'Book',
            'from_xml' => $from,
        ]],
    ]);
    $r = $client->__soapCall('getBook', []);
    echo (is_string($r) && $r === 'MAPPED:Dune') ? "from_xml=1\n" : 'from_xml=0:'.var_export($r, true)."\n";
} catch (Throwable $e) {
    echo 'err='.get_class($e).':'.$e->getMessage()."\n";
}
