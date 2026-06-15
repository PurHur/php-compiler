<?php
declare(strict_types=1);

enum E: string { case A = 'a'; }
class C { public const X = E::A; }
var_dump(constant(C::class . '::X'));
echo (constant('C::X') === E::A) ? "same\n" : "diff\n";
echo get_debug_type(constant('C::X')), "\n";
