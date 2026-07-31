--TEST--
Get-only virtual property write Error is "read-only" (PHP-8.4 zend_object_handlers.c; #26006)
--DESCRIPTION--
External write / ++ on get-only VIRTUAL hooked properties must match php-src PHP-8.4:
"Property C::$full is read-only". Do not use "Must not write to virtual property" here —
that string is only for raw backing-slot access inside a hook
(zend_throw_no_prop_backing_value_access). Backed get-only keeps a distinct message.
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
    public string $full {
        get => "x";
    }
    public int $n {
        get => 1;
    }
}
$c = new C();
try {
    $c->full = "y";
} catch (Error $e) {
    echo "arrow assign: ", $e->getMessage(), "\n";
}
try {
    $c->n++;
} catch (Error $e) {
    echo "arrow ++:     ", $e->getMessage(), "\n";
}

class E {
    public string $name = "ok" {
        get => $this->name;
    }
}
$e = new E();
try {
    $e->name = "no";
} catch (Error $e2) {
    echo "backed:       ", $e2->getMessage(), "\n";
}
--EXPECT--
arrow assign: Property C::$full is read-only
arrow ++:     Property C::$n is read-only
backed:       Cannot write property E::$name without set hook
