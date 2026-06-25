<?php

declare(strict_types=1);

// Issue #11818 — empty stream arrays must throw ValueError (ext/standard/streams.c).
$r = [];
$w = null;
$e = null;
try {
    stream_select($r, $w, $e, 0, 0);
    echo "unexpected_success=0\n";
} catch (ValueError) {
    echo "ok ValueError\n";
}
