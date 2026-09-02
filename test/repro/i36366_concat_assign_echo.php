<?php
// Repro: $out = "hello" . "\n"; echo $out; — AOT echoes empty (#36366 / p16)
$out = 'hello' . "\n";
echo $out;
