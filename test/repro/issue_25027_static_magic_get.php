<?php
// #25027 — static __get/__set/__isset/__unset/__call/__toString must be compile fatals (Zend/zend_compile.c).
class A { public static function __get($n) { return 1; } }
echo "accepted\n";
