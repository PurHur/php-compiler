<?php
// AOT: packed walks must keep TYPE_NULL (#33705 peer #33699).
$mapped = array_map(static fn($x) => $x, [null, 1, null]);
echo 'map:', count($mapped), ' ', json_encode($mapped), PHP_EOL;

$filtered = array_filter([null, 1, null, 2], static fn($x) => true);
echo 'filter:', count($filtered), ' ', json_encode($filtered), PHP_EOL;

$ita = iterator_to_array(new ArrayIterator([null, 1, null]));
echo 'ita:', count($ita), ' ', json_encode($ita), PHP_EOL;
