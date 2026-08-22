<?php
/**
 * #33695 — TYPE_VALUE array dim must not lower via ArrayAccess when ArrayObject is linked.
 * Untyped static by-value copy + json_decode(..., true) are packed HT boxes, not ArrayAccess.
 */
class A
{
    public static $a = [1];
}

$b = A::$a;
echo $b[0], "\n";
$b[0] = 99;
echo A::$a[0], "\n";

$j = json_decode('[1]', true);
echo $j[0], "\n";

// Keep ArrayObject linked so the overreach path would have been live.
$ao = new ArrayObject(['x' => 7]);
echo $ao['x'], "\n";
