<?php
/** Repro #21524 — getTraceAsString formats SensitiveParameterValue as Object(...). */
ini_set('zend.exception_ignore_args', '0');
function sp(#[\SensitiveParameter] string $p): void
{
    throw new Exception('boom');
}
try {
    sp('secret');
} catch (Throwable $e) {
    $line = explode("\n", $e->getTraceAsString())[0];
    echo $line, PHP_EOL;
    echo str_contains($line, 'Object(SensitiveParameterValue)') ? "object_form\n" : "no_object_form\n";
    echo str_contains($e->getTraceAsString(), 'secret') ? "leaked\n" : "no_leak\n";
    echo str_contains($e->getTraceAsString(), '[Sensitive Parameter]') ? "flat_label\n" : "no_flat_label\n";
    $arg = $e->getTrace()[0]['args'][0] ?? null;
    echo is_object($arg) ? get_class($arg) : 'missing', PHP_EOL;
}
