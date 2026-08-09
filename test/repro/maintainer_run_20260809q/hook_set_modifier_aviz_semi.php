<?php
class C {
    public string $x {
        get => 'g';
        private(set);
    }
}
$o = new C;
echo $o->x, "\n";
try { $o->x = 'x'; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
