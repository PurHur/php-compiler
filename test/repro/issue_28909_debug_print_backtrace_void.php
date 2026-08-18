<?php
/**
 * #28909 — debug_print_backtrace Reflection return void (basic_functions.stub.php).
 */
$r = new ReflectionFunction('debug_print_backtrace');
echo $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
