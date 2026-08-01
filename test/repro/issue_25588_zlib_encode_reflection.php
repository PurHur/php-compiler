<?php
declare(strict_types=1);

// #25588 — zlib_encode Reflection $level optional default -1 (php-src zlib.stub.php)
$r = new ReflectionFunction('zlib_encode');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' opt=', $p->isOptional() ? '1' : '0';
    if ($p->isOptional() && $p->isDefaultValueAvailable()) {
        echo ' def=', json_encode($p->getDefaultValue());
    }
    echo "\n";
}
echo 'req=', $r->getNumberOfRequiredParameters(), "\n";
