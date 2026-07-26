--TEST--
get_object_vars/get_class_methods named object/object_or_class arguments (JIT, issue #23401)
--FILE--
<?php
class C
{
    public $a = 1;
}
echo get_object_vars(object: new C)['a'], PHP_EOL;
echo in_array('format', get_class_methods(object_or_class: DateTime::class), true) ? 'yes' : 'no', PHP_EOL;
--EXPECT--
1
yes
