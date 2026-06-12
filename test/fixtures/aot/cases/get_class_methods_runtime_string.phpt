--TEST--
AOT get_class_methods() — runtime class-name string (#4752)
--FILE--
<?php
class Worker {
    public function run(): void {}
}
$name = Worker::class;
var_export(in_array('run', get_class_methods($name), true));
echo "\n";
--EXPECT--
true
