<?php

declare(strict_types=1);

/**
 * Issue #27051: AOT mb_strlen UTF-8 must match Zend (not silent 0).
 *
 * Covers literal+encoding (const-fold) and variable path (__compiler_utf8_strlen NestedJIT ABI).
 */
$s = "éclair";
echo mb_strlen("éclair", 'UTF-8'), PHP_EOL;
echo mb_strlen($s), PHP_EOL;
echo mb_strlen($s, 'UTF-8'), PHP_EOL;
echo mb_strlen('hello', 'UTF-8'), PHP_EOL;
