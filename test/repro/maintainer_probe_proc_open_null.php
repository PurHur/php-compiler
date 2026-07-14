<?php
// #18901 — proc_open(null) TypeError (ext/standard/proc_open.c).
try {
    $pipes = [];
    proc_open(null, [], $pipes);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
