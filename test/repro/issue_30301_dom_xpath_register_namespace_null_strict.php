<?php
declare(strict_types=1);
$d = new DOMDocument();
$d->loadXML('<r xmlns:p="urn:p"/>');
$x = new DOMXPath($d);
echo "start\n";
try {
    $x->registerNamespace(null, 'urn:x');
    echo "prefix=fail\n";
} catch (Throwable $t) {
    echo 'prefix=', get_class($t), ': ', $t->getMessage(), "\n";
}
try {
    $x->registerNamespace('p', null);
    echo "namespace=fail\n";
} catch (Throwable $t) {
    echo 'namespace=', get_class($t), ': ', $t->getMessage(), "\n";
}
echo "done\n";
