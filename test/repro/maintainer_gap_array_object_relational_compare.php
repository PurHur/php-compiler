<?php
/** Maintainer repro #12033 — array/object/null relational compare (Zend/zend_operators.c). */

$o = new stdClass();

echo ([] < $o) ? "true\n" : "false\n";
echo ([] > $o) ? "true\n" : "false\n";
echo (null < []) ? "true\n" : "false\n";
echo ([] == $o) ? "true\n" : "false\n";
