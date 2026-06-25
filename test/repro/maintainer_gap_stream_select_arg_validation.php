<?php
// Repro for #9216 — stream_select() ArgumentCountError + empty-array zero timeout.
try {
    stream_select();
} catch (Throwable $e) {
    echo get_class($e), "\n";
}

$r = [];
$w = null;
$e = null;
var_dump(stream_select($r, $w, $e, 0, 0));
