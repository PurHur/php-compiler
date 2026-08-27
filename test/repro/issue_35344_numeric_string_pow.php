<?php
/**
 * #35344 — numeric-string ** stays int when Zend does; float-strings stay float.
 */
var_dump(2 ** 3);
$a = 2;
$b = 3;
var_dump($a ** $b);
$a = "2";
var_dump($a ** 3);
$a = "2";
$b = "3";
var_dump($a ** $b);
var_dump("2.5" ** 2);
