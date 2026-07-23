--TEST--
Language: #[\SensitiveParameter] redacts Throwable::getTrace() args via SensitiveParameterValue (#21339/#21524/#22487, VM)
--FILE--
<?php
ini_set('zend.exception_ignore_args', '0');
function f(#[\SensitiveParameter] string $secret, string $ok): void {
    throw new Exception('trace');
}
try {
    f('hunter2', 'visible');
} catch (Exception $e) {
    $args = $e->getTrace()[0]['args'] ?? [];
    echo 'argc=', count($args), "\n";
    echo isset($args[0]) && is_object($args[0]) ? get_class($args[0]) : 'missing', "\n";
    $methods = isset($args[0]) && is_object($args[0]) ? get_class_methods($args[0]) : [];
    sort($methods);
    echo 'methods=', json_encode($methods), "\n";
    echo isset($args[0]) && is_object($args[0]) ? var_export($args[0]->getValue(), true) : 'missing', "\n";
    echo isset($args[1]) ? var_export($args[1], true) : 'missing', "\n";
    $traceString = $e->getTraceAsString();
    echo str_contains($traceString, 'hunter2') ? 'leaked' : 'no_leak', "\n";
    echo str_contains($traceString, 'Object(SensitiveParameterValue)') ? 'object_form' : 'no_object_form', "\n";
    echo str_contains($traceString, '[Sensitive Parameter]') ? 'flat_label' : 'no_flat_label', "\n";
    echo str_contains($traceString, 'visible') ? 'plain_visible' : 'no_plain', "\n";
}
--EXPECT--
argc=2
SensitiveParameterValue
methods=["__construct","__debugInfo","getValue"]
'hunter2'
'visible'
no_leak
object_form
no_flat_label
plain_visible
