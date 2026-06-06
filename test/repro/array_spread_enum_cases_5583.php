<?php
// Issue #5583 — array literal spread of Enum::cases() must preserve case objects.
enum E: int { case A = 1; case B = 2; }
$a = [...E::cases()];
var_export($a);
echo "\n";
var_export($a[0]);
echo "\n";
