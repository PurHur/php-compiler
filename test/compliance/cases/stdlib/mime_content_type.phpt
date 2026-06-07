--TEST--
stdlib mime_content_type() — PHP source file sniff (ext/standard/file.c; #6196)
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'phpc_mime_');
file_put_contents($path, "<?php echo 1;\n");
echo function_exists('mime_content_type') ? "fn\n" : "no-fn\n";
echo mime_content_type($path), "\n";
unlink($path);
--EXPECT--
fn
text/x-php
