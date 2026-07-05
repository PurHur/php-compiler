--TEST--
stdlib get_defined_functions() — withhold forward-profile phantom builtins on 8.4.0-dev (#16482, re-#16086)
--SKIPIF--
<?php
if (!getenv('PHP_COMPILER_PROFILE') || '8.4' !== getenv('PHP_COMPILER_PROFILE')) {
    die('skip requires PHP_COMPILER_PROFILE=8.4');
}
if (!PHPCompiler\CompilerVersion::supportsStrIncrement()) {
    die('skip str_increment not supported');
}
--FILE--
<?php
$internal = get_defined_functions()['internal'];
foreach (['str_increment', 'str_decrement', 'fpow', 'fmin', 'fmax'] as $fn) {
    echo $fn, '_fe=', function_exists($fn) ? '1' : '0', "\n";
    echo $fn, '_internal=', in_array($fn, $internal, true) ? '1' : '0', "\n";
}
--EXPECT--
str_increment_fe=0
str_increment_internal=0
str_decrement_fe=0
str_decrement_internal=0
fpow_fe=0
fpow_internal=0
fmin_fe=0
fmin_internal=0
fmax_fe=0
fmax_internal=0
