<?php
// #36366: string concat only
$out = 'hello' . "\n";
echo 'L', strlen($out), ':', $out;
