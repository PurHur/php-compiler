<?php

declare(strict_types=1);

/**
 * Issue #11675 — extension_loaded('gd') phantom probe (ext/gd/gd.c).
 */

$loaded = extension_loaded('gd') ? 'yes' : 'no';
$imagecreate = function_exists('imagecreate') ? 'yes' : 'no';

$probe = 'absent';
if ($imagecreate === 'yes') {
    try {
        imagecreate(1, 1);
        $probe = 'ok';
    } catch (Throwable $e) {
        $probe = $e::class;
    }
}

echo "loaded={$loaded} imagecreate={$imagecreate} probe={$probe}\n";

if ($loaded !== 'no' || $imagecreate !== 'no') {
    echo "fail: gd probe must be absent until ext/gd parity (#3496)\n";
    exit(1);
}

echo "ok\n";
