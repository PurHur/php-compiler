<?php
class C {
    public string $x {
        set => $this->x = strtoupper($value);
    }
}
$c = new C();
$c->x = 'hi';
try {
    var_dump(isset($c->x));
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_dump(empty($c->x));
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
