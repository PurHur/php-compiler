--TEST--
stdlib stripcslashes()/strtolower() JIT — null TypeError under declare(strict_types=1) (#18780, ext/standard/string.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);

foreach (['stripcslashes', 'strtolower'] as $fn) {
    try {
        $fn(null);
        echo "$fn: uncaught\n";
    } catch (TypeError $e) {
        echo $fn.': '.$e->getMessage()."\n";
    }
}
?>
--EXPECT--
stripcslashes: stripcslashes(): Argument #1 ($string) must be of type string, null given
strtolower: strtolower(): Argument #1 ($string) must be of type string, null given
