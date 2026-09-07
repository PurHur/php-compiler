<?php

declare(strict_types=1);

/**
 * Multi-arg nested isset + later `$a && $b` changes CFG so `$m[0]["t"]` sees bool (#36398).
 *
 * Without the trailing `&&`, VM matches Zend. With it, `$m[0]` becomes bool(true).
 *
 * php-src: Zend/zend_compile.c ZEND_ISSET_ISEMPTY_DIM_OBJ; Zend/zend_execute.c isset.
 */

$m = [];
$m[0] = ['v' => 0, 't' => 0];
$a = isset($m[0]['v'], $m[0]['t']);
$b = $m[0]['t'] === 0;
$c = $a && $b;
var_dump($a, $b, $c);
