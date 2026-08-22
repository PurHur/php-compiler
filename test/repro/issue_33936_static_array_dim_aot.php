<?php
// #33936 — dim fetch on static array across JUMP must match Zend (not empty/NULL).
class C
{
    public static $a;
}
C::$a = ['x' => 1];
echo C::$a['x'], "\n";
$v = C::$a['x'];
echo $v, "\n";
echo count(C::$a), ':', C::$a['x'], "\n";
