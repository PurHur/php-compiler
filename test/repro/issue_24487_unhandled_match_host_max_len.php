<?php
// Repro #24487 — host `php -d zend.exception_string_param_max_len=0 bin/vm.php`
// must redact UnhandledMatchError string subjects (Zend/zend_exceptions.c).
try {
    match ('secret-subject') { 1 => 'a' };
    echo "NO_THROW\n";
} catch (UnhandledMatchError $e) {
    echo $e->getMessage(), "\n";
}
echo ini_get('zend.exception_string_param_max_len'), "\n";
