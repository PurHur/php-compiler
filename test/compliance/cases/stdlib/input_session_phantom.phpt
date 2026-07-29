--TEST--
INPUT_SESSION not registered — php-src filter.stub.php surface (#24358)
--FILE--
<?php
echo defined('INPUT_SESSION') ? ('DEF='.INPUT_SESSION) : 'undefined', "\n";
foreach (['INPUT_GET', 'INPUT_POST', 'INPUT_COOKIE', 'INPUT_ENV', 'INPUT_SERVER', 'INPUT_REQUEST'] as $c) {
    echo $c, '=', defined($c) ? (string) constant($c) : 'U', "\n";
}
try {
    filter_input(6, 'x');
    echo "filter_input6=ok\n";
} catch (Throwable $e) {
    echo 'filter_input6=', get_class($e), ':', $e->getMessage(), "\n";
}
?>
--EXPECT--
undefined
INPUT_GET=1
INPUT_POST=0
INPUT_COOKIE=2
INPUT_ENV=4
INPUT_SERVER=5
INPUT_REQUEST=U
filter_input6=ValueError:filter_input(): Argument #1 ($type) must be an INPUT_* constant
