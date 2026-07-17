<?php
declare(strict_types=1);

/**
 * Repro for #20081 — sodium_increment / sodium_add / sodium_compare (VM).
 * Note: sodium_is_zero is NOT exposed by php-src ext/sodium (libsodium.stub.php).
 * AOT: see maintainer_gap_sodium_compare_aot.php (compare + function_exists).
 */
if (!extension_loaded('sodium')) {
    fwrite(STDERR, "skip: ext/sodium unavailable\n");
    exit(0);
}

foreach (['sodium_increment', 'sodium_add', 'sodium_compare'] as $fn) {
    if (!function_exists($fn)) {
        fwrite(STDERR, "fail: {$fn}() not registered\n");
        exit(1);
    }
    echo $fn, "=1\n";
}
echo 'sodium_is_zero=', function_exists('sodium_is_zero') ? '1' : '0', "\n";

$a = "\x00\x00\x00\x00";
sodium_increment($a);
echo 'inc=', bin2hex($a), "\n";

$a = "\xff\x00\x00\x00";
sodium_increment($a);
echo 'inc_carry=', bin2hex($a), "\n";

$b = "\x01\x00\x00\x00";
$c = "\x02\x00\x00\x00";
sodium_add($b, $c);
echo 'add=', bin2hex($b), "\n";

echo 'cmp_eq=', sodium_compare('ab', 'ab'), "\n";
echo 'cmp_lt=', sodium_compare("\x01\x00", "\x02\x00"), "\n";
echo 'cmp_gt=', sodium_compare("\x02\x00", "\x01\x00"), "\n";

try {
    $x = 1;
    sodium_increment($x);
    echo "inc_type_fail\n";
} catch (SodiumException $e) {
    echo "inc_type_ok\n";
}

try {
    sodium_compare('a', 'ab');
    echo "cmp_len_fail\n";
} catch (SodiumException $e) {
    echo "cmp_len_ok\n";
}

try {
    $x = 'a';
    sodium_add($x, 'ab');
    echo "add_len_fail\n";
} catch (SodiumException $e) {
    echo "add_len_ok\n";
}
