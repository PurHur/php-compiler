--TEST--
Language: uninitialized readonly property read throws Error (#4248)
--FILE--
<?php
class R {
    public readonly int $x;
    public function __construct() {
        echo "in ctor\n";
    }
}
$r = new R;
var_dump(isset($r->x));
try {
    var_dump($r->x);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo $r->x;
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
in ctor
bool(false)
Error: Typed property R::$x must not be accessed before initialization
Error: Typed property R::$x must not be accessed before initialization
