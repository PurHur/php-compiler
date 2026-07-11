--TEST--
stdlib str_pad() reordered named pad_type:/string:/length: JIT (#9582, ext/standard/string.c)
--FILE--
<?php
echo str_pad('x', 5, ' ', STR_PAD_LEFT), "\n";
echo str_pad(pad_type: STR_PAD_LEFT, string: 'x', length: 5), "\n";
--EXPECT--
    x
    x
