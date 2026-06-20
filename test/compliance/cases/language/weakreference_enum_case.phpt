--TEST--
language WeakReference::create() accepts enum case object (#10219)
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
