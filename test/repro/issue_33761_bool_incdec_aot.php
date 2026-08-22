<?php
// AOT: untyped bool ++/-- must stay bool (Zend/zend_operators.c IS_TRUE/IS_FALSE, #33761).
$t = true;
$t++;
var_dump($t);
$f = false;
$f++;
var_dump($f);
$td = true;
$td--;
var_dump($td);
$fd = false;
$fd--;
var_dump($fd);
$post = true;
$r = $post++;
var_dump($r, $post);
