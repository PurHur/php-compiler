<?php
class C {
    public string $name {
        get => 'g';
        private set(string $v) {}
    }
}
$o = new C;
echo $o->name, "\n";
try { $o->name = 'x'; } catch (Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
