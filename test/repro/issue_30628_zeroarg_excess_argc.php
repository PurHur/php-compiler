<?php
/**
 * preg_last_error / preg_last_error_msg / zend_version excess argc → ArgumentCountError (#30628).
 * php-src: ext/pcre/php_pcre.c + Zend/zend.c
 */
foreach ([
    'preg_last_error' => [
        static fn () => preg_last_error('x'),
        static fn () => preg_last_error(1, 2),
        static fn () => preg_last_error(),
    ],
    'preg_last_error_msg' => [
        static fn () => preg_last_error_msg('x'),
        static fn () => preg_last_error_msg(1, 2),
        static fn () => preg_last_error_msg(),
    ],
    'zend_version' => [
        static fn () => zend_version('x'),
        static fn () => zend_version(1, 2),
        static fn () => zend_version(),
    ],
] as $name => $calls) {
    foreach ($calls as $i => $fn) {
        try {
            $r = $fn();
            // Normalize zend_version() success — VM string is engine-fixed, not host Zend (#30628).
            if ('zend_version' === $name) {
                echo $name, '_', $i, ':OK:', is_string($r) && $r !== '' ? 'nonempty_string' : var_export($r, true), "\n";
            } else {
                echo $name, '_', $i, ':OK:', var_export($r, true), "\n";
            }
        } catch (ArgumentCountError $e) {
            echo $name, '_', $i, ':ArgumentCountError:', $e->getMessage(), "\n";
        } catch (Throwable $e) {
            echo $name, '_', $i, ':', get_class($e), ':', $e->getMessage(), "\n";
        }
    }
}
