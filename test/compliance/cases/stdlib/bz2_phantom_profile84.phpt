--TEST--
stdlib extension_loaded('bz2') withheld under PROFILE=8.4 without host (#25011)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('bz2') ? "fail ext\n" : "ok ext\n";
foreach (['bzcompress', 'bzdecompress'] as $fn) {
    echo function_exists($fn) ? "fail {$fn}\n" : "ok {$fn}\n";
}
--EXPECT--
ok ext
ok bzcompress
ok bzdecompress
