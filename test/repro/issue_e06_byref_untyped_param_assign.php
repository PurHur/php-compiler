<?php
/**
 * e06_byref / differential: untyped by-ref formal `$r = $v` must compile and match Zend.
 * Was invalid IR (copyBetweenPointers after sealed insert BB).
 */
function g(&$r, $v)
{
    $r = $v;
}
$out = null;
g($out, 5);
echo $out, "\n";
