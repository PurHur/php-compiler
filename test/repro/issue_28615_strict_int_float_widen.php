<?php
declare(strict_types=1);

class F { public float $f; }

function takes_float(float $x): float { return $x; }
function returns_float($x): float { return $x; }
function takes_int(int $x): int { return $x; }

echo "param=";
try { var_export(takes_float(1)); echo "\n"; }
catch (TypeError $e) { echo "TypeError: ", $e->getMessage(), "\n"; }

echo "ret=";
try { var_export(returns_float(1)); echo "\n"; }
catch (TypeError $e) { echo "TypeError: ", $e->getMessage(), "\n"; }

echo "prop=";
try { $o = new F; $o->f = 1; var_export($o->f); echo "\n"; }
catch (TypeError $e) { echo "TypeError: ", $e->getMessage(), "\n"; }

echo "float_to_int=";
try { var_export(takes_int(1.5)); echo "\n"; }
catch (TypeError $e) { echo "TypeError: ", $e->getMessage(), "\n"; }
