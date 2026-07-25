<?php
// Repro #23086 — Zend refuses serialize()/unserialize() of SensitiveParameterValue.
$s = new SensitiveParameterValue('secret');
try {
    echo serialize($s), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:23:"SensitiveParameterValue":1:{s:5:"value";s:6:"secret";}');
    echo "unserialize:ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:23:"SensitiveParameterValue":1:{s:30:"'."\0".'SensitiveParameterValue'."\0".'value";s:6:"secret";}');
    echo "unserialize-leak:ok\n";
} catch (Throwable $e) {
    echo 'leak:', get_class($e), ':', $e->getMessage(), "\n";
}
