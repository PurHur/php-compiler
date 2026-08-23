<?php

declare(strict_types=1);

/**
 * #27727 — getmyinode() Reflection return must be int|false (ext/standard/basic_functions.stub.php).
 */
$rf = new ReflectionFunction('getmyinode');
echo 'return=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '(none)', "\n";
