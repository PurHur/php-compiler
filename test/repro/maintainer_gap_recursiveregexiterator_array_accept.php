<?php
$it = new RecursiveRegexIterator(new RecursiveArrayIterator(["a1", ["b2", "cc", ["e4"]], "d3", "xx"]), "/\\d/");
$top = [];
foreach ($it as $v) { $top[] = is_array($v) ? "ARR" : $v; }
echo "top=", implode(",", $top), "\n";
echo "RII=", implode(",", iterator_to_array(new RecursiveIteratorIterator($it), false)), "\n";
