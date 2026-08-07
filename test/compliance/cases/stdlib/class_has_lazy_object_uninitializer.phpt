--TEST--
stdlib class_has_lazy_object_uninitializer() — never advertised (#28517, re-#6097)
--FILE--
<?php
echo (int) function_exists('class_has_lazy_object_uninitializer'), "\n";
--EXPECT--
0
