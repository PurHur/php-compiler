<?php

/**
 * Repro #30946 — Directory::{read,rewind,close} excess argc → ArgumentCountError.
 * php-src: ext/standard/dir.c — php_dir_read / rewind / close
 */
$d = dir('/tmp');
foreach (['read', 'rewind', 'close'] as $m) {
    try {
        $d->$m('x');
        echo $m, ': OK', "\n";
    } catch (Throwable $e) {
        echo $m, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}

$d2 = dir('/tmp');
$first = $d2->read();
$d2->rewind();
$d2->close();
echo 'ok=', is_string($first) ? '1' : '0', "\n";
