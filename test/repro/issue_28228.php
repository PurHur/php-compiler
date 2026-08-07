<?php
/**
 * #28228 — strstr/stristr/strchr excess argc → ArgumentCountError (Zend), not LogicException.
 *
 * php-src: ext/standard/string.stub.php / string.c
 */
$cases = [
    'strstr' => static function () {
        strstr('abcdef', 'c', false, true);
    },
    'stristr' => static function () {
        stristr('abcdef', 'c', false, true);
    },
    'strchr' => static function () {
        strchr('abcdef', 'c', false, true);
    },
    'strstr_ok' => static function () {
        return (string) strstr('abcdef', 'c');
    },
    'strstr_before' => static function () {
        return (string) strstr('abcdef', 'c', true);
    },
    'stristr_ok' => static function () {
        return (string) stristr('AbCdEf', 'c');
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
