<?php
// AOT: fopen(data://) must match Zend RFC2397 wrapper (#34744).
// php-src: ext/standard/php_data_wrapper.c
$f = fopen('data://text/plain,hi', 'r');
echo 'plain:';
var_dump(false === $f ? false : fread($f, 10));
if (is_resource($f)) {
    fclose($f);
}
$f = fopen('data://text/plain;base64,aGk=', 'r');
echo 'base64:';
var_dump(false === $f ? false : fread($f, 10));
if (is_resource($f)) {
    fclose($f);
}
$f = fopen('data://text/plain,hi', 'w');
echo 'write:';
var_dump($f !== false);
if (is_resource($f)) {
    fclose($f);
}
