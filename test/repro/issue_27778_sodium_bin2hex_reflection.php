<?php
/**
 * #27778 — sodium_bin2hex()/sodium_hex2bin() Reflection: string stubs + named args.
 */
foreach (['sodium_bin2hex', 'sodium_hex2bin'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' arity=', $r->getNumberOfParameters(),
        ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
    foreach ($r->getParameters() as $p) {
        $def = '';
        if ($p->isOptional() && $p->isDefaultValueAvailable()) {
            $def = '=' . var_export($p->getDefaultValue(), true);
        }
        echo '  ', $p->getName(), ':', $p->hasType() ? (string) $p->getType() : '?', $def, "\n";
    }
}
echo 'named_bin=', sodium_bin2hex(string: 'a'), "\n";
echo 'pos_bin=', sodium_bin2hex('a'), "\n";
echo 'named_hex=', sodium_hex2bin(string: '61', ignore: ''), "\n";
echo 'pos_hex=', sodium_hex2bin('61'), "\n";
