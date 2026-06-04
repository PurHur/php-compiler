<?php
enum E: string { case A = 'a'; }
class C { public const X = E::A; }
var_dump(constant(C::class . '::X'));
