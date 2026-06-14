<?php declare(strict_types=1);

function g(?string $n): ?string { return null === $n ? null : $n; }
function f(?string $n): ?string { return null !== $n ? $n : null; }

echo 'g=', var_export(g('hello'), true), ' f=', var_export(f('hello'), true), "\n";
