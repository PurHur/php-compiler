<?php
/**
 * #28313 — string/hash helpers excess argc → ArgumentCountError (Zend), not LogicException.
 *
 * php-src: ext/standard/string.stub.php, crc32.c, basic_functions.stub.php
 */
$cases = [
    'str_rot13_ok' => static function () {
        return str_rot13('ab');
    },
    'hebrev_ok' => static function () {
        return hebrev('a', 0);
    },
    'quoted_ok' => static function () {
        return quoted_printable_decode('a');
    },
    'crc32_ok' => static function () {
        return (string) crc32('foo');
    },
    'md5_ok' => static function () {
        return md5('a', false);
    },
    'sha1_ok' => static function () {
        return sha1('a', false);
    },
    'str_shuffle' => static function () {
        str_shuffle('ab', 'x');
    },
    'str_rot13' => static function () {
        str_rot13('a', 'x');
    },
    'hebrev' => static function () {
        hebrev('a', 0, 'x');
    },
    'quoted_printable_decode' => static function () {
        quoted_printable_decode('a', 'x');
    },
    'crc32' => static function () {
        crc32('a', 'x');
    },
    'md5' => static function () {
        md5('a', true, 'x');
    },
    'sha1' => static function () {
        sha1('a', true, 'x');
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
