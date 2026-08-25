<?php

declare(strict_types=1);

/**
 * #34667 — AOT bool array dimension must coerce like Zend (true→1, false→0).
 *
 * @see php-src Zend/zend_execute.c zend_fetch_dimension_*
 */
$a = ['1' => 7];
echo $a[true], "\n";

$a2 = ['1' => 1];
$k = true;
var_dump(isset($a2[$k]));
