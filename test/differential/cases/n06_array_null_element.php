<?php
// FAILS ON AOT — #24232. A null stored in an array reads back as int(0), so === null and is_null()
// are silently false. var_dump() of the element is the smoking gun: int(0) instead of NULL.
//
// Bounding evidence: a scalar null is fine ($z === null passes), and isset() on the element agrees
// with Zend (false for an existing-but-null element) — so null-ness survives into the isset path
// while the READ path yields a zeroed long. Every way of getting a null in is affected: literal in
// an array literal, assignment into an existing array, and a null-valued variable.
$a = [1, null, 3];
$b = [1, 2, 3];
$b[1] = null;
$z = null;
$c = [1, $z, 3];
echo ($a[1] === null) ? 'n' : 'x';
echo is_null($a[1]) ? 'n' : 'x';
echo ($b[1] === null) ? 'n' : 'x';
echo ($c[1] === null) ? 'n' : 'x';
echo "\n";
var_dump($a[1]);
