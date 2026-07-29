<?php
// Repro #24486 — host `php -d zend.exception_string_param_max_len=N bin/vm.php`
// must truncate string args in Throwable::getTraceAsString() (Zend/zend_exceptions.c).
// Companion to UnhandledMatchError host sync (#24487); guest ini_set is covered by
// test/compliance/cases/language/exception_string_param_max_len.phpt (#21999).
ini_set('zend.exception_ignore_args', '0');
function g(string $a): void
{
    throw new Exception('x');
}
try {
    g('hello');
} catch (Throwable $e) {
    $line = explode("\n", $e->getTraceAsString())[0];
    echo $line, "\n";
    echo ini_get('zend.exception_string_param_max_len'), "\n";
}
