<?php
// #24137: json_decode() of a RUNTIME-produced string returns unusable data — both reads below come
// back empty/0 while the encoded string itself prints correctly. Decoding a string LITERAL works
// (that was #24116, fixed), which is exactly why this case round-trips instead: it is how JSON is
// actually used, and the literal-only probes all passed.
// FAILS AOT today by design; becomes a live guard when #24137 lands.
$d = ['a' => 1, 'b' => [2, 3]];
$j = json_encode($d);
$r = json_decode($j, true);
echo $j, ' ', $r['a'], ' ', $r['b'][1], "\n";
