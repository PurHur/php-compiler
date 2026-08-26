<?php
/**
 * #34896 — top-level closure reads class static property default (AOT).
 *
 * @differential-repeat: 3
 */
class D34896
{
    public static $v = 7;
}

$f = function () {
    return D34896::$v;
};
echo $f(), "\n";
$g = fn () => D34896::$v;
echo $g(), "\n";
