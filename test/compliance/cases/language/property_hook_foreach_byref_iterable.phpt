--TEST--
Language: foreach-by-ref on hooked array property — Indirect modification Error (#29215, zend_property_hooks.c)
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
    public array $items {
        get => $this->items;
        set => $this->items = $value;
    }
}
$h = new H;
$h->items = [1, 2, 3];
try {
    foreach ($h->items as &$v) {
        $v *= 10;
    }
    unset($v);
    echo 'mutated=';
    var_export($h->items);
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
    echo 'unchanged=';
    var_export($h->items);
    echo "\n";
}

// By-value foreach still reads through get.
foreach ($h->items as $v) {
    echo $v, ',';
}
echo "\n";

// &get allows FE_RESET_RW mutation (zend_property_hooks.c).
class G {
    private array $_items = [1, 2, 3];
    public array $items {
        &get => $this->_items;
    }
}
$g = new G;
foreach ($g->items as &$v) {
    $v *= 10;
}
unset($v);
echo 'byref_get=';
var_export($g->items);
echo "\n";
?>
--EXPECT--
Error:Indirect modification of H::$items is not allowed
unchanged=array (
  0 => 1,
  1 => 2,
  2 => 3,
)
1,2,3,
byref_get=array (
  0 => 10,
  1 => 20,
  2 => 30,
)
