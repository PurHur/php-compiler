--TEST--
Virtual get-only property ?? invokes get hook (#29266, zend_object_handlers.c)
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
class C {
    public string $x {
        get => 'hello';
    }
}
$o = new C;
echo $o->x, "\n";
echo ($o->x ?? 'rhs'), "\n";

class D {
    public ?string $y {
        get => null;
    }
}
$d = new D;
var_export($d->y ?? 'rhs');
echo "\n";
--EXPECT--
hello
hello
'rhs'
