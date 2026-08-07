<?php
// #28625 — AOT ArrayObject foreach + encapsed echo (SXE string-cast fold must not hijack plain strings)
$o = new ArrayObject(['a' => 1, 'b' => 2]);
foreach ($o as $k => $v) {
    echo "$k=$v;";
}
echo "\n";
