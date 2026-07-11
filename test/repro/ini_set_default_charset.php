<?php

$prev = ini_set('default_charset', 'UTF-8');
echo 'ini_set_return: ', var_export($prev, true), "\n";
echo 'ini_get: ', var_export(ini_get('default_charset'), true), "\n";
if (false === $prev) {
    echo "FAIL: ini_set('default_charset') returned false; Zend returns previous value\n";
    exit(1);
}
$changed = ini_set('default_charset', 'ISO-8859-1');
echo 'changed_from: ', var_export($changed, true), "\n";
echo 'after_set: ', var_export(ini_get('default_charset'), true), "\n";
if ('ISO-8859-1' !== ini_get('default_charset')) {
    echo "FAIL: ini_get('default_charset') not updated\n";
    exit(1);
}
echo "ok\n";
