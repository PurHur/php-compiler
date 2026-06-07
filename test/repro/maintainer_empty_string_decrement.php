<?php
/** Issue #6757 — empty string -- must coerce to int(-1) (Zend/zend_operators.c). */
$s = '';
var_dump($s--);
var_dump($s);

$s = '';
var_dump(--$s);
var_dump($s);
