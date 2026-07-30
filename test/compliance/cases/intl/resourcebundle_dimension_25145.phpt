--TEST--
ResourceBundle $bundle["key"] read_dimension; isset/write/unset Error (#25145)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip ResourceBundle withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$b = ResourceBundle::create('en', 'ICUDATA-region', false);
if (false === $b || null === $b) {
    echo "create_failed\n";
    exit(0);
}
try {
    $x = $b['Countries'];
    echo 'get=', get_class($x), "\n";
    $via = $b->get('Countries');
    echo 'same_class=', (int) (get_class($x) === get_class($via)), "\n";
    echo 'count_child=', count($x), "\n";
} catch (Throwable $e) {
    echo 'get=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $isset = isset($b['Countries']) ? 'Y' : 'N';
    echo 'isset=', $isset, "\n";
} catch (Throwable $e) {
    echo 'isset=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $b['Countries'] = 1;
    echo "set=ok\n";
} catch (Throwable $e) {
    echo 'set=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unset($b['Countries']);
    echo "unset=ok\n";
} catch (Throwable $e) {
    echo 'unset=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $y = $b[0];
    echo 'idx0=', is_object($y) ? get_class($y) : gettype($y), "\n";
} catch (Throwable $e) {
    echo 'idx0=', get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECTF--
get=ResourceBundle
same_class=1
count_child=%d
isset=Error:Cannot use object of type ResourceBundle as array
set=Error:Cannot use object of type ResourceBundle as array
unset=Error:Cannot use object of type ResourceBundle as array
idx0=ResourceBundle
