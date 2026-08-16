--TEST--
stdlib trim/ltrim/rtrim/chop(null $characters) TypeError under strict_types (#31386, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);
foreach (['trim', 'ltrim', 'rtrim', 'chop'] as $fn) {
    try {
        $fn(' x ', null);
        echo "$fn: fail\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
trim(): Argument #2 ($characters) must be of type string, null given
ltrim(): Argument #2 ($characters) must be of type string, null given
rtrim(): Argument #2 ($characters) must be of type string, null given
chop(): Argument #2 ($characters) must be of type string, null given
