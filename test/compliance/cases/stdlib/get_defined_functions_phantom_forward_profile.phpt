--TEST--
stdlib get_defined_functions() — fpow on forward profile; IEEE phantoms withheld (#28565, #16482)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!PHPCompiler\CompilerVersion::supportsStrIncrement()) {
    die('skip str_increment not supported');
}
--FILE--
<?php
$internal = get_defined_functions()['internal'];
foreach (['str_increment', 'str_decrement', 'fpow'] as $fn) {
    echo $fn, '_fe=', function_exists($fn) ? '1' : '0', "\n";
    echo $fn, '_internal=', in_array($fn, $internal, true) ? '1' : '0', "\n";
}
foreach (['fmin', 'fmax', 'fadd', 'fsub', 'fmul', 'nextafter'] as $fn) {
    echo $fn, '_fe=', function_exists($fn) ? '1' : '0', "\n";
    echo $fn, '_internal=', in_array($fn, $internal, true) ? '1' : '0', "\n";
}
--EXPECT--
str_increment_fe=1
str_increment_internal=1
str_decrement_fe=1
str_decrement_internal=1
fpow_fe=1
fpow_internal=1
fmin_fe=0
fmin_internal=0
fmax_fe=0
fmax_internal=0
fadd_fe=0
fadd_internal=0
fsub_fe=0
fsub_internal=0
fmul_fe=0
fmul_internal=0
nextafter_fe=0
nextafter_internal=0
