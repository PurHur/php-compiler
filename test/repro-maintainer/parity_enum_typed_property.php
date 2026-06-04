<?php
enum E: int { case A = 1; }

class C { public E $e; }
class R { public readonly E $e; function __construct() { $this->e = E::A; } }
class U { public readonly E $e; }

function assign_int_to_e(C $c): void { $c->e = 1; }

$c = new C; $c->e = E::A;
try { assign_int_to_e($c); } catch (Throwable $e) { echo "assign: ", get_class($e), ": ", $e->getMessage(), "\n"; }

var_export((new R)->e); echo "\n";

try { var_export((new U)->e); } catch (Throwable $e) { echo "uninit: ", get_class($e), ": ", $e->getMessage(), "\n"; }
