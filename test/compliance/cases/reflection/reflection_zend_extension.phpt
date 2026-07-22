--TEST--
ReflectionZendExtension class + Zend OPcache getters (#22248)
--FILE--
<?php
var_export(class_exists('ReflectionZendExtension'));
echo "\n";
echo in_array('Zend OPcache', get_loaded_extensions(true), true) ? "has_zend\n" : "no_zend\n";
$r = new ReflectionZendExtension('Zend OPcache');
echo $r->getName(), "\n";
echo $r->getAuthor(), "\n";
echo $r->getURL(), "\n";
echo $r->getCopyright(), "\n";
echo strlen($r->getVersion()) > 0 ? "version_ok\n" : "version_bad\n";
echo str_starts_with((string) $r, 'Zend Extension [ Zend OPcache ') ? "tostring_ok\n" : "tostring_bad\n";
try {
    new ReflectionZendExtension('nope');
    echo "missing_ok\n";
} catch (ReflectionException $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
has_zend
Zend OPcache
Zend Technologies
http://www.zend.com/
Copyright (c)
version_ok
tostring_ok
Zend Extension "nope" does not exist
