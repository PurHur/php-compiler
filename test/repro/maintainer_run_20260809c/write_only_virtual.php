<?php
class Hso {
    public $x {
        set {}
    }
}
$o = new Hso();
try {
    echo $o->x, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_dump(isset($o->x));
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo ($o->x ?? 'default'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
