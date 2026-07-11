--TEST--
stdlib brotli — not advertised on reference profile (#17563, ext/brotli)
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('brotli') ? "fail ext\n" : "ok ext\n";
foreach (['brotli_compress', 'brotli_uncompress'] as $fn) {
    echo function_exists($fn) ? "fail {$fn}\n" : "ok {$fn}\n";
}
--EXPECT--
ok ext
ok brotli_compress
ok brotli_uncompress
