--TEST--
Language: &$obj->hookedProp without &get invokes get then Indirect modification (#29719, zend_object_handlers.c)
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
class C {
    public string $prop {
        get { echo "GET\n"; return $this->prop ??= ""; }
        set { echo "SET ".$value."\n"; $this->prop = $value; }
    }
}
$o = new C();
$o->prop = "a";
try {
    $r =& $o->prop;
    echo "ref_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}

// Virtual: same get-then-Error path.
class V {
    public string $prop {
        get { echo "VGET\n"; return "x"; }
        set { echo "VSET ".$value."\n"; }
    }
}
$v = new V();
$v->prop = "a";
try {
    $r =& $v->prop;
    echo "v_ref_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}

// Object from get: Zend allows the temporary (#29719 / zend_object_handlers.c).
class O {
    public $prop {
        get { echo "OGET\n"; return new stdClass; }
        set { echo "OSET\n"; }
    }
}
$obj = new O();
$obj->prop = 1;
try {
    $r =& $obj->prop;
    echo "obj_ref_ok type=", gettype($r), "\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}

// &get still aliases (#22475).
class G {
    private int $n = 0;
    public int $count {
        &get => $this->n;
        set(int $v) { $this->n = $v; }
    }
}
$g = new G;
$g->count = 3;
$r =& $g->count;
$r = 9;
echo "byref_get=", $g->count, "\n";
?>
--EXPECT--
SET a
GET
Error: Indirect modification of C::$prop is not allowed
VSET a
VGET
Error: Indirect modification of V::$prop is not allowed
OSET
OGET
obj_ref_ok type=object
byref_get=9
