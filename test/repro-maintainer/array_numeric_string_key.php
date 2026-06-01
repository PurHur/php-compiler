<?php

/** Issue #3679 — numeric string keys match int keys (Zend zend_hash.c). */
$a = [1 => 'v'];
var_dump(array_key_exists('1', $a));
var_dump(isset($a['1']));
var_dump($a['1'] ?? 'missing');

$b = ['01' => 'leading'];
var_dump(array_key_exists('01', $b));
var_dump(isset($b['1']));
