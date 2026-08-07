<?php
/**
 * #28311 — string search/span excess argc → ArgumentCountError (Zend), not LogicException.
 *
 * php-src: ext/standard/string.stub.php / string.c
 */
$cases = [
    'strcspn' => static function () {
        strcspn('abc', 'a', 0, 1, 'x');
    },
    'strspn' => static function () {
        strspn('abc', 'a', 0, 1, 'x');
    },
    'substr_count' => static function () {
        substr_count('aaa', 'a', 0, 1, 'x');
    },
    'stripos' => static function () {
        stripos('abc', 'a', 0, 'x');
    },
    'strripos' => static function () {
        strripos('abc', 'a', 0, 'x');
    },
    'strrpos' => static function () {
        strrpos('abc', 'a', 0, 'x');
    },
    'strpos_peer' => static function () {
        // Already ACE — must stay on ACE path (#21964 / #28311).
        strpos('abc', 'a', 0, 'x');
    },
    'strcspn_ok' => static function () {
        return (string) strcspn('abc', 'b');
    },
    'stripos_ok' => static function () {
        return (string) stripos('AbC', 'b');
    },
];

foreach ($cases as $name => $fn) {
    try {
        $r = $fn();
        echo $name, ':OK:', (string) $r, "\n";
    } catch (Throwable $e) {
        echo $name, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
