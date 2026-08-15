--TEST--
Language: host/production zend.assertions=-1 — assert(false) no-op (#31195, Zend/zend_compile.c)
--INI--
zend.assertions=-1
--FILE--
<?php
error_reporting(E_ALL);
echo 'assertions=', var_export(ini_get('zend.assertions'), true), "\n";
try {
    assert(false, 'nope');
    echo "SURVIVED\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
assertions='-1'
SURVIVED
