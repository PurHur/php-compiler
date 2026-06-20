--TEST--
stdlib putenv() invalid assignment syntax throws ValueError (#10335, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

foreach (['=invalid', '=', ''] as $assignment) {
    try {
        putenv($assignment);
        echo json_encode($assignment), ": uncaught\n";
    } catch (ValueError $e) {
        echo json_encode($assignment), ': ', $e->getMessage(), "\n";
    }
}

var_export(putenv('PHP_COMPILER_PUTENV_TEST=ok'));
echo "\n";
var_export(getenv('PHP_COMPILER_PUTENV_TEST'));
echo "\n";
putenv('PHP_COMPILER_PUTENV_TEST');
--EXPECT--
"=invalid": putenv(): Argument #1 ($assignment) must have a valid syntax
"=": putenv(): Argument #1 ($assignment) must have a valid syntax
"": putenv(): Argument #1 ($assignment) must have a valid syntax
true
'ok'
