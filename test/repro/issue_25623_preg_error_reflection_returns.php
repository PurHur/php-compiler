<?php
/**
 * #25623 — preg_last_error_msg(): string / error_clear_last(): void Reflection returns.
 * php-src: ext/standard/basic_functions.stub.php
 */
foreach (['preg_last_error_msg', 'error_clear_last'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, '|ret:', $r->hasReturnType() ? (string) $r->getReturnType() : '-', "\n";
}
@preg_match('/(/', 'x');
echo 'msg=', preg_last_error_msg(), "\n";
@trigger_error('t', E_USER_NOTICE);
error_clear_last();
var_export(error_get_last());
echo "\n";
