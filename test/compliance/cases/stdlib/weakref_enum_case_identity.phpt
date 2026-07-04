--TEST--
WeakReference::create() backed enum case — get() identity after var_export (#15739, ext/weakref/php_weakref.c)
--FILE--
<?php
declare(strict_types=1);

enum E: int { case A = 1; }

$wr = WeakReference::create(E::A);
var_export($wr->get());
echo "\n";
echo $wr->get() === E::A ? "same\n" : "diff\n";
--EXPECT--
\E::A
same
