--TEST--
stdlib curl_file_create() + CURLFile getters/setters (#6790, ext/curl/curl_file.c)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
declare(strict_types=1);

echo function_exists('curl_file_create') ? "fn_ok\n" : "fn_missing\n";
echo class_exists('CURLFile', false) ? "class_ok\n" : "class_missing\n";

$f = curl_file_create('/tmp/x.txt', 'text/plain', 'upload.txt');
echo $f instanceof CURLFile ? "instance_ok\n" : "instance_bad\n";
echo $f->getFilename(), "\n";
echo $f->getMimeType(), "\n";
echo $f->getPostFilename(), "\n";
echo $f->name, "\n";
echo $f->mime, "\n";
echo $f->postname, "\n";

$f2 = new CURLFile('/var/data.bin');
echo $f2->getFilename(), "\n";
echo $f2->getMimeType(), "\n";
echo $f2->getPostFilename(), "\n";

$f->setMimeType('application/json');
echo $f->getMimeType(), "\n";
$f->setPostFilename('renamed.txt');
echo $f->getPostFilename(), "\n";
--EXPECT--
fn_ok
class_ok
instance_ok
/tmp/x.txt
text/plain
upload.txt
/tmp/x.txt
text/plain
upload.txt
/var/data.bin


application/json
renamed.txt
