--TEST--
stdlib CURLStringFile — construct + public properties (#16659, #6918)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
declare(strict_types=1);

echo class_exists('CURLStringFile', false) ? "class_ok\n" : "class_missing\n";
$f = new CURLStringFile('payload', 'upload.txt', 'text/plain');
echo $f->data, "\n";
echo $f->postname, "\n";
echo $f->mime, "\n";
--EXPECT--
class_ok
payload
upload.txt
text/plain
