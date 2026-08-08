<?php
ini_set('zend.exception_ignore_args', '0');
try {
    array_rand([]);
} catch (ValueError $e) {
    echo "caught\n";
    echo $e->getTraceAsString(), "\n";
    $t = $e->getTrace();
    echo 'fn=', ($t[0]['function'] ?? '?'), "\n";
    echo 'has_args=', (isset($t[0]['args']) ? 'yes' : 'no'), "\n";
    echo 'nargs=', (isset($t[0]['args']) ? count($t[0]['args']) : -1), "\n";
    echo 'arg0_is_array=', (isset($t[0]['args'][0]) && is_array($t[0]['args'][0]) ? 'yes' : 'no'), "\n";
}
