--TEST--
language user-arg TypeError/ArgumentCountError frames use call-site file/line not [internal function] (issue #29023, Zend/zend_execute.c)
--FILE--
<?php
ini_set('zend.exception_ignore_args', '0');

$defArg = __LINE__ + 1;
function bad_arg(int $x): void {}
class C
{
    public function m(int $x): void {}
}
$defMeth = (new ReflectionMethod('C', 'm'))->getStartLine();

try {
    $callArg = __LINE__ + 1;
    bad_arg('x');
} catch (TypeError $e) {
    echo 'arg_line_is_def=', ($e->getLine() === $defArg) ? 'yes' : 'no', "\n";
    $t0 = $e->getTrace()[0];
    echo 'arg_t0_file=', isset($t0['file']) ? 'yes' : 'no', "\n";
    echo 'arg_t0_line_is_call=', (($t0['line'] ?? 0) === $callArg) ? 'yes' : 'no', "\n";
    echo 'arg_t0_fn=', $t0['function'] ?? '?', "\n";
    echo (str_contains($e->getTraceAsString(), '[internal function]') ? 'arg_internal' : 'arg_user'), "\n";
    echo (str_contains($e->getTraceAsString(), 'bad_arg(') ? 'arg_has_callee' : 'arg_no_callee'), "\n";
}

try {
    $callMeth = __LINE__ + 1;
    (new C())->m('x');
} catch (TypeError $e) {
    echo 'meth_line_is_def=', ($e->getLine() === $defMeth) ? 'yes' : 'no', "\n";
    $t0 = $e->getTrace()[0];
    echo 'meth_t0_line_is_call=', (($t0['line'] ?? 0) === $callMeth) ? 'yes' : 'no', "\n";
    echo 'meth_t0_fn=', $t0['function'] ?? '?', "\n";
    echo 'meth_t0_class=', $t0['class'] ?? '?', "\n";
    echo (str_contains($e->getTraceAsString(), '[internal function]') ? 'meth_internal' : 'meth_user'), "\n";
    echo (str_contains($e->getTraceAsString(), 'C->m(') ? 'meth_has_callee' : 'meth_no_callee'), "\n";
}

try {
    $callArgc = __LINE__ + 1;
    bad_arg();
} catch (ArgumentCountError $e) {
    echo 'argc_line_is_def=', ($e->getLine() === $defArg) ? 'yes' : 'no', "\n";
    $t0 = $e->getTrace()[0];
    echo 'argc_t0_line_is_call=', (($t0['line'] ?? 0) === $callArgc) ? 'yes' : 'no', "\n";
    echo 'argc_t0_fn=', $t0['function'] ?? '?', "\n";
    echo (str_contains($e->getTraceAsString(), '[internal function]') ? 'argc_internal' : 'argc_user'), "\n";
}
?>
--EXPECT--
arg_line_is_def=yes
arg_t0_file=yes
arg_t0_line_is_call=yes
arg_t0_fn=bad_arg
arg_user
arg_has_callee
meth_line_is_def=yes
meth_t0_line_is_call=yes
meth_t0_fn=m
meth_t0_class=C
meth_user
meth_has_callee
argc_line_is_def=yes
argc_t0_line_is_call=yes
argc_t0_fn=bad_arg
argc_user
