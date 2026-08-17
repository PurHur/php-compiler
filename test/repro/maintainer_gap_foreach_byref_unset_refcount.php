<?php
// #31936 — foreach-by-ref then unset($v) must drop IS_REFERENCE markers (Zend zend_variables.c).
$a = [1, 2, 3];
foreach ($a as &$v) {
    $v *= 2;
}
unset($v);
var_dump($a);

echo "--- before unset ---\n";
$b = [1, 2, 3];
foreach ($b as &$v) {
    $v *= 2;
}
var_dump($b);
unset($v);

echo "--- extra alias ---\n";
$c = [1, 2, 3];
foreach ($c as &$v) {
    $v *= 2;
}
$keep =& $v;
unset($v);
var_dump($c);
unset($keep);
var_dump($c);
