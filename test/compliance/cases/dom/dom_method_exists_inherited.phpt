--TEST--
DOM method_exists() — inherited DOMNode methods visible on child class names (#19178, ext/standard/class.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
putenv('PHP_COMPILER_PROFILE=8.4');
if (!PHPCompiler\CompilerVersion::supportsDomNodeContains()) {
    die('skip PHP 8.4 DOM profile required');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

$fail = false;
foreach ([
    ['DOMElement', 'after'],
    ['DOMElement', 'append'],
    ['DOMNode', 'appendChild'],
    ['DOMNode', 'contains'],
] as [$class, $method]) {
    if (!method_exists($class, $method)) {
        $fail = true;
    }
}
echo $fail ? "fail\n" : "ok\n";
--EXPECT--
ok
