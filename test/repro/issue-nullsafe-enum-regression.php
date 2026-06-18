<?php
declare(strict_types=1);

enum E: int { case A = 1; }

var_export(E::A?->name);
echo "\n";
var_export(E::A?->value);
echo "\n";

enum M: int {
    case X = 1;
    public function id(): int { return $this->value; }
}
var_export(M::X?->id());
echo "\n";

$null = null;
var_export($null?->foo);
echo "\n";
