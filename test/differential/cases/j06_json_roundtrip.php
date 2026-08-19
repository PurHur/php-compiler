<?php
// #24137: json_decode() of a RUNTIME-produced string — AOT guard (encode→decode roundtrip).
// @differential-skip-aot: nested packed-array index via JsonDecodeJitHelper still wrong-output (#24137 follow-up)
$d = ['a' => 1, 'b' => [2, 3]];
$j = json_encode($d);
$r = json_decode($j, true);
echo $j, ' ', $r['a'], ' ', $r['b'][1], "\n";
