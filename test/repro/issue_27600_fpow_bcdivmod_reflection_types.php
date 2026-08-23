<?php
/**
 * VM: fpow/bcdivmod Reflection types match php-src stubs (#27600).
 */
declare(strict_types=1);

foreach (['fpow', 'bcdivmod'] as $fn) {
    $r = new ReflectionFunction($fn);
    $bits = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() : '?';
        $bits[] = $t.' $'.$p->getName();
    }
    $ret = $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE';
    echo $fn, ' ret=', $ret, ' params=[', implode(', ', $bits), "]\n";
}
var_dump(fpow(num: 2.0, exponent: 3.0));
var_dump(bcdivmod(num1: '10', num2: '3'));
