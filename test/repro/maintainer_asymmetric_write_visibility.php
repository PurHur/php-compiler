<?php
class C {
    public private(set) int $x = 1;
}
$c = new C();
echo $c->x, "\n";
try { $c->x = 2; } catch (Throwable $e) { echo get_class($e), "\n"; }
