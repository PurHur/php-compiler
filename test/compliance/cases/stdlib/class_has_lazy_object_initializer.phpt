--TEST--
stdlib class_has_lazy_object_initializer() — never advertised (#28517, re-#6052)
--FILE--
<?php
echo (int) function_exists('class_has_lazy_object_initializer'), "\n";
--EXPECT--
0
