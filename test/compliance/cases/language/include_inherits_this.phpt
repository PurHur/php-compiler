--TEST--
Language: include from instance method inherits $this (ZEND_INCLUDE_OR_EVAL, #31903)
--RUNFILE--
include_inherits_this/entry.php
--EXPECT--
include=7
require=7
include_once=7
require_once=7
static=Error: Using $this when not in object context
function=Error: Using $this when not in object context
file=Error: Using $this when not in object context
