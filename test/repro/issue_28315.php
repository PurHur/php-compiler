<?php
/**
 * #28315 — hash helpers excess argc → ArgumentCountError / TypeError (Zend), not LogicException.
 *
 * php-src: ext/hash/hash.stub.php / hash.c
 */
$file = tempnam(sys_get_temp_dir(), 'h28315');
file_put_contents($file, 'hi');

$cases = [
    'hash_file_ok' => static function () use ($file) {
        return substr((string) hash_file('md5', $file), 0, 8);
    },
    'hash_file_options_ok' => static function () use ($file) {
        return substr((string) hash_file('md5', $file, false, []), 0, 8);
    },
    'hash_file_options' => static function () use ($file) {
        hash_file('md5', $file, false, 'x');
    },
    'hash_hmac_file' => static function () use ($file) {
        hash_hmac_file('md5', $file, 'k', false, 'x');
    },
    'hash_update' => static function () {
        hash_update(hash_init('md5'), 'a', 'x');
    },
    'hash_final' => static function () {
        hash_final(hash_init('md5'), false, 'x');
    },
    'hash_copy' => static function () {
        hash_copy(hash_init('md5'), 'x');
    },
    'hash_equals' => static function () {
        hash_equals('a', 'a', 'x');
    },
    'hash_hkdf' => static function () {
        hash_hkdf('sha256', 'ikm', 0, '', '', 'x');
    },
    'hash_hmac_ok' => static function () {
        // Already ACE on excess argc — must stay on ACE path (#28315).
        hash_hmac('md5', 'a', 'k', false, 'x');
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

@unlink($file);
