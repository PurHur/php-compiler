<?php
// #3492 — header_register_callback() must invoke closures before body output (head.c).
$ok = false;
header_register_callback(function () use (&$ok): void {
    $ok = true;
});
echo 'body';
echo "\n", $ok ? 'callback' : 'missing', "\n";
