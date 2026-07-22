<?php
/**
 * Repro #22248 — ReflectionZendExtension class + Zend OPcache metadata.
 */
var_export(class_exists('ReflectionZendExtension'));
echo "\n";
$zend = get_loaded_extensions(true);
echo in_array('Zend OPcache', $zend, true) ? "has_zend_opcache\n" : "no_zend_opcache\n";
try {
    $r = new ReflectionZendExtension('Zend OPcache');
    echo $r->getName(), "\n";
    echo $r->getAuthor(), "\n";
    echo $r->getURL(), "\n";
    echo $r->getCopyright(), "\n";
    echo strlen($r->getVersion()) > 0 ? "version_ok\n" : "version_bad\n";
    echo (string) $r === '' ? "tostring_empty\n" : "tostring_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    new ReflectionZendExtension('nope');
    echo "missing_ok_unexpected\n";
} catch (ReflectionException $e) {
    echo $e->getMessage(), "\n";
}
