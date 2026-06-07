--TEST--
stdlib mime_content_type() — JIT/AOT path (#6196)
--JIT--
--FILE--
<?php
$path = tempnam(sys_get_temp_dir(), 'phpc_mime_jit_');
file_put_contents($path, "<?php echo 1;\n");
echo mime_content_type($path), "\n";
unlink($path);
--EXPECT--
text/x-php
