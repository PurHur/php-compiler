--TEST--
AOT: bool ++/-- stays bool (Zend IS_TRUE/IS_FALSE no-op, #33761)
--FILE--
<?php
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
?>
--EXPECT--
bool(true)
bool(false)
bool(true)
bool(false)
bool(true)
bool(true)
