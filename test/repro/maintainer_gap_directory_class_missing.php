<?php

declare(strict_types=1);

$d = dir('.');
$entry = $d->read();
$ok = class_exists('Directory', false)
    && $d instanceof Directory
    && is_string($d->path)
    && $entry !== false
    && is_string($entry);

if ($ok) {
    $d->rewind();
    $d->close();
}

echo $ok ? "ok\n" : "fail\n";
