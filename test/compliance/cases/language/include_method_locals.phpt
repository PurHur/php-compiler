--TEST--
include from method inherits caller locals (issue #2059; Zend include T op_array)
--RUNFILE--
include_method_locals/entry.php
--EXPECT--
Hello
