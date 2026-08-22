<?php
// AOT: json_encode must see post-INIT_ARRAY dim mutations (#33709).
$a = [1];
$a[] = null;
$a[] = 2;
echo json_encode($a), PHP_EOL;

$b = [1, 2, 3];
unset($b[1]);
echo json_encode($b), PHP_EOL;

$c = [1];
$c[] = false;
$c[] = 'x';
echo json_encode($c), PHP_EOL;
