<?php
// Repro #34506: thin AOT var_export/print_r/var_dump(object) must match Zend (no SIGABRT).
// Keep object vars live so Zend object handles are #1/#2/#3 (not recycled temps).
$empty = new stdClass;
$one = (object) ['a' => 1];
$nest = (object) ['a' => 1, 'nest' => [2]];
echo var_export($empty, true), "\n";
echo var_export($one, true), "\n";
echo var_export($nest, true), "\n";
echo print_r($empty, true);
echo print_r($one, true);
echo print_r($nest, true);
var_dump($empty);
var_dump($one);
var_dump($nest);
