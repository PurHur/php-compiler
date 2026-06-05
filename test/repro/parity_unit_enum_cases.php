<?php
declare(strict_types=1);

enum U { case A; case B; }

$cases = U::cases();
echo ($cases[0] instanceof U) ? '1' : '0', "\n";
echo ($cases[0] === U::A) ? '1' : '0', "\n";

enum E: int { case A = 1; case B = 2; }

$backed = E::cases();
echo ($backed[0] === E::A) ? '1' : '0', "\n";
