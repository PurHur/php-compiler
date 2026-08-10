--TEST--
Get-hook $this->prop ?? / isset / empty on uninitialized backing match Zend (#29688, zend_property_hooks.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\CompilerVersion')) {
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
class CoalesceGet {
    public string $prop {
        get => $this->prop ?? "default";
        set => $this->prop = $value;
    }
}
$c = new CoalesceGet;
echo $c->prop, "\n";

class IssetGet {
    public string $prop {
        get => isset($this->prop) ? $this->prop : "missing";
        set => $this->prop = $value;
    }
}
$i = new IssetGet;
echo $i->prop, "\n";

class EmptyGet {
    public string $prop {
        get {
            echo empty($this->prop) ? "E1" : "E0";
            echo isset($this->prop) ? "I1" : "I0";
            return $this->prop ?? "x";
        }
        set => $this->prop = $value;
    }
}
$e = new EmptyGet;
echo $e->prop, "\n";
$e->prop = "hi";
echo $e->prop, "\n";

class BareGet {
    public string $prop {
        get => $this->prop;
        set => $this->prop = $value;
    }
}
$b = new BareGet;
try {
    echo $b->prop, "\n";
} catch (Error $err) {
    echo "err\n";
}

class NullCoalesceAssign {
    public ?string $prop {
        get => $this->prop ?? null;
        set => $this->prop = $value;
    }
}
$n = new NullCoalesceAssign;
$n->prop ??= "assigned";
echo $n->prop, "\n";
--EXPECT--
default
missing
E1I0x
E0I1hi
err
assigned
