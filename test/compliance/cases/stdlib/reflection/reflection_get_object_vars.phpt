--TEST--
get_object_vars on ReflectionClass/Method/Object exports name/class (#22515, ext/standard/var.c)
--FILE--
<?php
class T { function f(): void {} }
$rc = new ReflectionClass(T::class);
$rm = new ReflectionMethod(T::class, 'f');
$ro = new ReflectionObject(new T());
echo 'RC=', json_encode(get_object_vars($rc)), "\n";
echo 'RM=', json_encode(get_object_vars($rm)), "\n";
echo 'RO=', json_encode(get_object_vars($ro)), "\n";
echo 'name=', var_export($rc->name, true), "\n";
--EXPECT--
RC={"name":"T"}
RM={"class":"T","name":"f"}
RO={"name":"T"}
name='T'
