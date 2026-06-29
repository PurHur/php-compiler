--TEST--
stdlib vsprintf()/vprintf() — TypeError when values arg is not array (#13589, ext/standard/sprintf.c)
--FILE--
<?php
declare(strict_types=1);

foreach (['vsprintf', 'vprintf'] as $fn) {
    try {
        $fn('%s', 'hi');
        echo "$fn: uncaught\n";
    } catch (TypeError $e) {
        echo "$fn: ", $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
vsprintf: vsprintf(): Argument #2 ($values) must be of type array, string given
vprintf: vprintf(): Argument #2 ($values) must be of type array, string given
