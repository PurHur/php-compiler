--TEST--
readonly anonymous property Error uses class@anonymous (no NUL/path) (#29267, zend_object_handlers.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsReadonlyAnonymousClass()) {
    die('skip new readonly class requires PHP 8.3+ forward profile');
}
?>
--FILE--
<?php
$o = new readonly class {
    public function __construct(public string $a = "x") {}
};
try {
    $o->a = "y";
    echo "UNEXPECTED_OK\n";
} catch (Error $e) {
    $msg = $e->getMessage();
    echo $msg, "\n";
    echo "has_nul=", (strpos($msg, "\0") !== false ? "yes" : "no"), "\n";
}
--EXPECT--
Cannot modify readonly property class@anonymous::$a
has_nul=no
