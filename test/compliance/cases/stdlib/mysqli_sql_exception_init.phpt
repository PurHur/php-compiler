--TEST--
stdlib mysqli_sql_exception class + mysqli_init() + new mysqli() (#21803, ext/mysqli/mysqli.c)
--ENV--
PHP_COMPILER_ENABLE_MYSQLI=1
--FILE--
<?php
echo class_exists('mysqli_sql_exception') ? 'ex=yes' : 'ex=no', "\n";
echo class_exists('mysqli_driver') ? 'drv=yes' : 'drv=no', "\n";
echo function_exists('mysqli_init') ? 'init=yes' : 'init=no', "\n";
$m = mysqli_init();
echo is_object($m) ? 'init_obj=yes' : 'init_obj=no', "\n";
echo $m instanceof mysqli ? 'init_is_mysqli=yes' : 'init_is_mysqli=no', "\n";
$m2 = new mysqli();
echo is_object($m2) ? 'new_obj=yes' : 'new_obj=no', "\n";
$r = new ReflectionClass('mysqli_sql_exception');
echo 'parent=', $r->getParentClass() ? $r->getParentClass()->getName() : 'none', "\n";
?>
--EXPECT--
ex=yes
drv=yes
init=yes
init_obj=yes
init_is_mysqli=yes
new_obj=yes
parent=RuntimeException
