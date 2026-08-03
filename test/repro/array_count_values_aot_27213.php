<?php
// #27213 — thin AOT array_count_values must print frequency map (not abort/empty).
$c = array_count_values(['a', 'b', 'a']);
echo $c['a'], ',', $c['b'], "\n";
