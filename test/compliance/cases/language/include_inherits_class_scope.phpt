--TEST--
Language: include from instance method inherits self/static class scope (#31913)
--RUNFILE--
include_inherits_class_scope/entry.php
--EXPECT--
include=self=C
require=self=C
include_once=self=C
require_once=self=C
static_lsb=static=D
file=Error: Cannot access "self" when no class scope is active
