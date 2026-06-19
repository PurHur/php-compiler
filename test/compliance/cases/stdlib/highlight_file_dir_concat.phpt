--TEST--
stdlib highlight_file() __DIR__/__FILE__ concat path (#9924, Zend/zend_compile.c)
--RUNFILE--
highlight_file_dir_concat.php
--EXPECT--
string
file-code
string
