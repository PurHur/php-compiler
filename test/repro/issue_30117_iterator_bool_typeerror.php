<?php
// #30117 — iterator_* (false|true) TypeError says false|true not bool
foreach (['iterator_to_array', 'iterator_count', 'iterator_apply'] as $fn) {
    foreach ([false, true, null] as $v) {
        try {
            if ('iterator_apply' === $fn) {
                $fn($v, static function () {
                    return true;
                });
            } else {
                $fn($v);
            }
        } catch (Throwable $e) {
            echo $fn, ':', $e->getMessage(), PHP_EOL;
        }
    }
}
