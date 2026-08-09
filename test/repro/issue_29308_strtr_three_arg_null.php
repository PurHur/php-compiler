<?php
/**
 * #29308 — three-arg strtr() null $from/$to: Zend 8.4 DEP+coerce (not TypeError).
 * php-src: ext/standard/string.c PHP_FUNCTION(strtr)
 */
error_reporting(E_ALL);
$deps = [];
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    if ($no === E_DEPRECATED) {
        $deps[] = $msg;
    }

    return true;
});

foreach ([
    'from_null' => static fn () => strtr('a', null, 'x'),
    'to_null' => static fn () => strtr('a', 'a', null),
    'both_null' => static fn () => strtr('a', null, null),
    'two_arg_null' => static fn () => strtr('a', null),
] as $label => $fn) {
    $deps = [];
    echo "== $label ==\n";
    try {
        $r = $fn();
        echo 'OK:'.var_export($r, true)."\n";
    } catch (Throwable $e) {
        echo get_class($e).':'.$e->getMessage()."\n";
    }
    foreach ($deps as $d) {
        echo 'DEP:'.$d."\n";
    }
}
