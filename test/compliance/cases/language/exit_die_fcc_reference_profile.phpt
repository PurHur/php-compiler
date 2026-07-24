--TEST--
Language: exit/die FCC rejected on reference profile (#22796, zend_closures.c / zend_compile.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (PHPCompiler\CompilerVersion::supportsExitFunctionForm()) {
    die('skip exit function form enabled on PHP 8.4.0+ target');
}
?>
--FILE--
<?php
foreach (['exit', 'die'] as $c) {
    try {
        $f = $c(...);
        echo $c, '=fcc:', is_callable($f) ? 'yes' : 'no', "\n";
    } catch (Error $e) {
        echo $c, '=Error:', $e->getMessage(), "\n";
    }
}
try {
    Closure::fromCallable('exit');
    echo "fromCallable=ok\n";
} catch (TypeError $e) {
    echo 'fromCallable=TypeError:', $e->getMessage(), "\n";
}
try {
    eval('exit(status: 0);');
    echo "named_survived\n";
} catch (ParseError $e) {
    echo 'named_parse:', $e->getMessage(), "\n";
}
?>
--EXPECT--
exit=Error:Call to undefined function exit()
die=Error:Call to undefined function die()
fromCallable=TypeError:Failed to create closure from callable: function "exit" not found or invalid function name
named_parse:syntax error, unexpected token ":"
