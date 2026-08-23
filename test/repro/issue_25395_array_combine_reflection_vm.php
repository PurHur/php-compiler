<?php
/**
 * #25395 — array_combine() Reflection return must be array (not array|false).
 * php-src: ext/standard/array.stub.php
 */
declare(strict_types=1);

$r = new ReflectionFunction('array_combine');
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
