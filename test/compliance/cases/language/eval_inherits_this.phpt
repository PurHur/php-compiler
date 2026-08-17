--TEST--
Language: eval from instance method inherits $this (ZEND_INCLUDE_OR_EVAL, #31902)
--RUNFILE--
eval_inherits_this/entry.php
--EXPECT--
7
9
Error: Using $this when not in object context
file=Error: Using $this when not in object context
