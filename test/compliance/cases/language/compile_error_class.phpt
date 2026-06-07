--TEST--
Language: CompileError built-in class — class_exists and catch (Zend/zend_exceptions.c, #6368)
--FILE--
<?php
var_export(class_exists('CompileError'));
echo "\n";
try {
    throw new CompileError('probe');
} catch (CompileError $e) {
    echo "CompileError\n";
} catch (Error $e) {
    echo "Error only\n";
}

try {
    throw new CompileError('nested');
} catch (Error $e) {
    echo get_class($e), "\n";
}

try {
    throw new CompileError('throwable');
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
true
CompileError
CompileError
CompileError
