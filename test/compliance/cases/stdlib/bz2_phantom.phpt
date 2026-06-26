--TEST--
stdlib bz2 — not advertised on reference profile (#11992, ext/bz2)
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
