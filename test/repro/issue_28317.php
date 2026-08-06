<?php
/**
 * #28317 — excess argc → ArgumentCountError (Zend), not LogicException.
 */
$cases = [
    'strtolower' => static function () { strtolower('A', 'x'); },
    'strtoupper' => static function () { strtoupper('a', 'x'); },
    'ucfirst' => static function () { ucfirst('a', 'x'); },
    'lcfirst' => static function () { lcfirst('A', 'x'); },
    'ucwords' => static function () { ucwords('a b', ' ', 'x'); },
    'strrev' => static function () { strrev('ab', 'x'); },
    'quotemeta' => static function () { quotemeta('a.', 'x'); },
    'htmlentities' => static function () { htmlentities('a', ENT_QUOTES, 'UTF-8', true, 'x'); },
    'html_entity_decode' => static function () { html_entity_decode('&amp;', ENT_QUOTES, 'UTF-8', 'x'); },
    'get_debug_type' => static function () { get_debug_type(1, 'x'); },
    'is_iterable' => static function () { is_iterable([], 'x'); },
];
foreach ($cases as $name => $fn) {
    try {
        $fn();
        echo $name, ":OK\n";
    } catch (Throwable $e) {
        echo $name, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
