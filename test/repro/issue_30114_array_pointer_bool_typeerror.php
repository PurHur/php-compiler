<?php
// #30114 — reset/next/prev/current/key/end(false|true) TypeError says false|true not bool
foreach (['reset', 'next', 'prev', 'current', 'key', 'end'] as $fn) {
    foreach ([false, true, null] as $v) {
        $a = $v;
        try {
            $fn($a);
        } catch (Throwable $e) {
            echo $fn, ':', $e->getMessage(), PHP_EOL;
        }
    }
}
