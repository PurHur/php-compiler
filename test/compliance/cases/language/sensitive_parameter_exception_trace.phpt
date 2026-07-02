--TEST--
Language: #[\SensitiveParameter] omits args from Throwable::getTrace() (VM, #15036)
--FILE--
<?php
function f(#[\SensitiveParameter] string $secret): void {
    throw new Exception('trace');
}
try {
    f('hunter2');
} catch (Exception $e) {
    echo array_key_exists('args', $e->getTrace()[0]) ? 'has_args' : 'no_args', "\n";
    $traceString = $e->getTraceAsString();
    echo str_contains($traceString, '[Sensitive Parameter]') ? 'redacted_label' : 'no_redacted_label', "\n";
    echo str_contains($traceString, 'f()') ? 'bare_call' : 'no_bare_call', "\n";
}
--EXPECT--
no_args
no_redacted_label
bare_call
