<?php
$a = ResourceBundle::create('en', 'ICUDATA-region');
$b = new ResourceBundle('en', 'ICUDATA-region');
echo 'create=', $a->count(), ' new=', $b->count(), "\n";
echo 'hasCtor=', method_exists('ResourceBundle', '__construct') ? 'yes' : 'no', "\n";
$rc = new ReflectionClass('ResourceBundle');
echo 'reflCtor=', $rc->getConstructor() ? $rc->getConstructor()->getName() : 'null', "\n";
try {
    new ResourceBundle('xx_NOPE', 'ICUDATA-region', false);
    echo "no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
