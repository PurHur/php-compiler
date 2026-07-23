--TEST--
stdlib attribute_exists()/class_meth_exists()/unitenum_exists()/crc32c() — absent on PROFILE=8.4 (#22584, re-#14995/#17138)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['attribute_exists', 'class_meth_exists', 'unitenum_exists', 'crc32c'] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}
--EXPECT--
attribute_exists=0
class_meth_exists=0
unitenum_exists=0
crc32c=0
