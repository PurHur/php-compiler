--TEST--
Language: isset/empty/?? on uninitialized backed hooked property skip get (#30739, #11617, zend_property_hooks.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.4');
if (!PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
    die('skip property hooks require PHP_COMPILER_PROFILE=8.4');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public string $name {
        get { echo "GET\n"; return $this->name; }
        set(string $v) => $this->name = $v;
    }
}
$c = new C;
try {
    var_export(isset($c->name));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(empty($c->name));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo ($c->name ?? 'd'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$c->name = 'x';
var_export(isset($c->name));
echo "\n";

class Plain {
    public string $name;
}
$p = new Plain;
try {
    var_export(isset($p->name));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
false
true
d
GET
true
false
