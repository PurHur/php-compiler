<?php
$r = json_decode(json_encode(['a' => 1, 'b' => [2, 3]]), true);
echo $r['a'], ' ', $r['b'][1], "\n";
