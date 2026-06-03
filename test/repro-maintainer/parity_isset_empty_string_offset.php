<?php

/** Issue #5307 — isset()/empty() on string offsets (Zend/zend_operators.c). */

$s = 'abc';
echo isset($s[0]) ? "true\n" : "false\n";
echo isset($s[99]) ? "true\n" : "false\n";

$s = 'hello';
echo empty($s[99]) ? "true\n" : "false\n";
