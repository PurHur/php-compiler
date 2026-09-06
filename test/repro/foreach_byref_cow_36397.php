<?php
// By-ref foreach after by-value copy must SEPARATE (ZEND_FE_RESET_RW / #36397).
$b = [1, 2];
$a = $b;
foreach ($a as &$v) {
    $v *= 10;
}
unset($v);
echo implode(',', $b), '|', implode(',', $a), "\n";
