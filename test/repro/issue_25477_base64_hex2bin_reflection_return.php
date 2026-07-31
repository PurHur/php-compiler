<?php
declare(strict_types=1);

// #25477: base64_decode/hex2bin Reflection return string|false.
foreach (['base64_decode', 'hex2bin'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ret=', (string) $r->getReturnType(), "\n";
}
var_export(base64_decode('!!!', true));
echo "\n";
var_export(@hex2bin('zz'));
echo "\n";
echo base64_decode('Zm9v'), "\n";
echo hex2bin('666f6f'), "\n";
