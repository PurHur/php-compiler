<?php

declare(strict_types=1);

foreach ([
    'DeflateContext' => deflate_init(ZLIB_ENCODING_DEFLATE),
    'InflateContext' => inflate_init(ZLIB_ENCODING_DEFLATE),
] as $name => $obj) {
    try {
        serialize($obj);
        echo $name." serialize:no-throw\n";
    } catch (Throwable $e) {
        echo $name.' serialize '.get_class($e).':'.$e->getMessage()."\n";
    }
    $payload = 'O:'.strlen($name).':"'.$name.'":0:{}';
    try {
        unserialize($payload);
        echo $name." unserialize:no-throw\n";
    } catch (Throwable $e) {
        echo $name.' unserialize '.get_class($e).':'.$e->getMessage()."\n";
    }
}
