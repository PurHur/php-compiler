<?php
ini_set('zend.exception_ignore_args', '0');
function login(#[\SensitiveParameter] string $password): void {
    throw new Exception('fail');
}
try { login('secret'); } catch (Throwable $e) {
    $arg = $e->getTrace()[0]['args'][0];
    echo 'class=', get_class($arg), "\n";
    echo 'methods=', json_encode(get_class_methods($arg)), "\n";
    try {
        echo 'getValue=', var_export($arg->getValue(), true), "\n";
    } catch (Throwable $ex) {
        echo 'getValue_err=', get_class($ex), ': ', $ex->getMessage(), "\n";
    }
    echo 'manual=', var_export((new SensitiveParameterValue('x'))->getValue(), true), "\n";
    echo 'asString=', $e->getTraceAsString(), "\n";
}
