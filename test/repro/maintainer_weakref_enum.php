<?php
declare(strict_types=1);

enum E: int { case A = 1; }

$wr = WeakReference::create(E::A);
var_export($wr->get());
echo "\n";
echo $wr->get() === E::A ? "same\n" : "diff\n";
