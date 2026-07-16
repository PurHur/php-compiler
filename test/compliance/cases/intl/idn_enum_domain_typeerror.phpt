--TEST--
idn_to_ascii()/idn_to_utf8() enum case domain must TypeError (#6169, php-src-strict)
--SKIPIF--
<?php
if (!function_exists('idn_to_ascii')) {
    die('skip idn builtins not advertised');
}
?>
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'example.com'; }

foreach (['idn_to_ascii', 'idn_to_utf8'] as $fn) {
    try {
        $fn(E::A);
        echo "$fn: no_throw\n";
    } catch (TypeError $e) {
        echo "$fn: TypeError\n";
    }
}
?>
--EXPECT--
idn_to_ascii: TypeError
idn_to_utf8: TypeError
