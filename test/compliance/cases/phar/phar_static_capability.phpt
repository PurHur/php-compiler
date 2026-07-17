--TEST--
ext/phar Phar::canWrite/canCompress/apiVersion/isValidPharFilename (#19871, ext/phar/phar_object.c)
--FILE--
<?php
foreach (['canWrite', 'canCompress', 'apiVersion', 'isValidPharFilename', 'running'] as $m) {
    echo $m . '=' . (method_exists('Phar', $m) ? '1' : '0') . "\n";
}
var_export(Phar::canWrite());
echo "\n";
var_export(Phar::canCompress());
echo "\n";
var_export(Phar::apiVersion());
echo "\n";
var_export(Phar::isValidPharFilename('x.phar'));
echo "\n";
var_export(Phar::isValidPharFilename('x.txt'));
echo "\n";
var_export(Phar::isValidPharFilename('x.phar', false));
echo "\n";
var_export(Phar::GZ === 4096);
echo "\n";
--EXPECT--
canWrite=1
canCompress=1
apiVersion=1
isValidPharFilename=1
running=1
false
true
'1.1.1'
true
false
false
true
