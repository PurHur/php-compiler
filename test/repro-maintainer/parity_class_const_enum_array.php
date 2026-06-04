<?php
enum E: int { case X = 1; }
class C { public const AR = [E::X]; }
var_export(C::AR[0] === E::X);
echo PHP_EOL;
