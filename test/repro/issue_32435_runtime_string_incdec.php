<?php
/**
 * #32435 — runtime string ++/-- must use Zend increment_string / numeric convert.
 * Literal `'a'++` already folds; function-return value boxes used to readLong→0
 * and print `1`, or SIGSEGV bin/compile.php without LLVM_ASSERT.
 */
function letters()
{
    return 'a';
}
function nines()
{
    return '9';
}
function zee()
{
    return 'z';
}

$s = letters();
$s++;
echo $s, "\n";

$n = nines();
$n++;
var_dump($n);

$z = zee();
$z++;
echo $z, "\n";

$d = letters();
$d--;
echo $d, "\n";
