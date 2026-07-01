--TEST--
gzcompress() default compression level matches Zend level 6 (#14584, ext/zlib/zlib.c)
--FILE--
<?php
$default = gzcompress('test');
$explicit = gzcompress('test', 6);
echo bin2hex(substr($default, 0, 2)) === '789c' ? "header_ok\n" : "header_bad\n";
echo $default === $explicit ? "match_ok\n" : "match_bad\n";
--EXPECT--
header_ok
match_ok
