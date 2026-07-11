--TEST--
stdlib msgpack — not advertised on reference profile (#17994, ext/msgpack)
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('msgpack') ? "fail ext\n" : "ok ext\n";
foreach (['msgpack_pack', 'msgpack_unpack'] as $fn) {
    echo function_exists($fn) ? "fail {$fn}\n" : "ok {$fn}\n";
}
--EXPECT--
ok ext
ok msgpack_pack
ok msgpack_unpack
