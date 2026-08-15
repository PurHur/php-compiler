<?php
/** #27618 — mb_str_pad Reflection types (re-#23805, php-src mbstring.stub.php). */
$r = new ReflectionFunction('mb_str_pad');
echo 'return=', (string) $r->getReturnType(), "\n";
foreach ($r->getParameters() as $p) {
    $t = $p->getType();
    echo $p->getName(), ':', null === $t ? 'NONE' : (string) $t;
    echo ':opt=', $p->isOptional() ? '1' : '0';
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo ':def=', var_export($p->getDefaultValue(), true);
    }
    echo "\n";
}
echo mb_str_pad(string: 'a', length: 5, pad_string: '.'), "\n";
