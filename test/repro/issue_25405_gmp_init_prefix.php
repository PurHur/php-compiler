<?php
declare(strict_types=1);
// Repro #25405 — gmp_init auto-detects 0x/0b/0o when base omitted or 0 (php-src convert_zstr_to_gmp).
foreach ([['0x10', '16'], ['0X10', '16'], ['0b1010', '10'], ['0B1010', '10'], ['0o17', '15']] as [$in, $want]) {
    $got = gmp_strval(gmp_init($in));
    echo "gmp_init($in)=$got want=$want ", $got === $want ? 'ok' : 'FAIL', "\n";
}
echo 'explicit16=', gmp_strval(gmp_init('10', 16)), "\n";
try {
    gmp_init('0x10', 10);
    echo "base10_prefix uncaught\n";
} catch (ValueError $e) {
    echo 'base10_prefix=', $e->getMessage(), "\n";
}
