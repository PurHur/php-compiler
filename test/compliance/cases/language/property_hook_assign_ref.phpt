--TEST--
By-reference assign to hooked properties requires &get (#22475, #6426, zend_object_handlers.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.4');
if (!PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip requires PHP_COMPILER_PROFILE=8.4 property hooks gate');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class H {
    private int $v = 1;
    public int $x {
        get => $this->v;
        set (int $value) { $this->v = $value; }
    }
}
$h = new H();
try {
    $b =& $h->x;
    echo "getset_ref_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $arr = [&$h->x];
    echo "arr_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $tmp = 9;
    $h->x =& $tmp;
    echo "lhs_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

class G {
    private int $v = 1;
    public int $x {
        get => $this->v;
    }
}
$g = new G();
try {
    $r =& $g->x;
    echo "assigned\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

class V {
    private int $n = 0;
    public int $count {
        &get => $this->n;
        set(int $v) { $this->n = $v; }
    }
}
$v = new V;
$v->count = 3;
$r =& $v->count;
$r = 9;
echo $v->count, "\n";

class A {
    private $_prop = 1;
    public $prop {
        &get => $this->_prop;
    }
}
$a = new A();
$ref = &$a->prop;
$ref = 42;
echo $a->prop, "\n";
--EXPECT--
Error: Indirect modification of H::$x is not allowed
Error: Indirect modification of H::$x is not allowed
Error: Cannot assign by reference to overloaded object
Error: Indirect modification of G::$x is not allowed
9
42
