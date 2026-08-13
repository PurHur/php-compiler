<?php
/**
 * json_last_error / json_last_error_msg excess argc → ArgumentCountError (#30591).
 * php-src: ext/json/json.c / json.stub.php
 */
foreach ([
    'json_last_error' => [
        static fn () => json_last_error('x'),
        static fn () => json_last_error(1, 2),
        static fn () => json_last_error(),
    ],
    'json_last_error_msg' => [
        static fn () => json_last_error_msg('x'),
        static fn () => json_last_error_msg(1, 2),
        static fn () => json_last_error_msg(),
    ],
] as $name => $calls) {
    foreach ($calls as $i => $fn) {
        try {
            $r = $fn();
            echo $name, '_', $i, ':OK:', var_export($r, true), "\n";
        } catch (ArgumentCountError $e) {
            echo $name, '_', $i, ':ArgumentCountError:', $e->getMessage(), "\n";
        } catch (Throwable $e) {
            echo $name, '_', $i, ':', get_class($e), ':', $e->getMessage(), "\n";
        }
    }
}
