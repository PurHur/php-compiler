--TEST--
stdlib mime_content_type() — ASCII plain text text/plain (#12116, ext/standard/file.c)
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'phpc_mime_plain_');
file_put_contents($path, "127.0.0.1 localhost\n");
echo mime_content_type($path), "\n";
unlink($path);
--EXPECT--
text/plain
