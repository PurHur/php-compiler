<?php

declare(strict_types=1);

/**
 * AOT: ArrayObject/ArrayIterator getArrayCopy must preserve keys after unset holes (#34002).
 *
 * php-src: ext/spl/spl_array.c zim_ArrayObject_getArrayCopy / zim_ArrayIterator_getArrayCopy
 */
$ao = new ArrayObject([1, 2, 3]);
$ao[] = 4;
unset($ao[1]);
$aoCopy = $ao->getArrayCopy();
echo implode(',', array_keys($aoCopy)), '|', implode(',', $aoCopy), "\n";

$ai = new ArrayIterator([1, 2, 3]);
$ai[] = 4;
unset($ai[1]);
$aiCopy = $ai->getArrayCopy();
echo is_array($aiCopy) ? 'arr' : gettype($aiCopy), '|';
echo implode(',', array_keys($aiCopy)), '|', implode(',', $aiCopy), "\n";

$named = new ArrayObject(['a' => 1, 'b' => 2, 'c' => 3]);
unset($named['b']);
echo implode(',', array_keys($named->getArrayCopy())), "\n";
