--TEST--
Language: class constants with object expressions — var_export + ctor args (#5169)
--RUNFILE--
class_const_new_object_run.php
--EXPECT--
(object) array (
)
1
Foo::__set_state(array (
  'x' => 7,
))
1
