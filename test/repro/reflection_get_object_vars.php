<?php
// #22515 — get_object_vars on ReflectionClass/Method matches Zend public props.
class T
{
    function f(): void
    {
    }
}

$rc = new ReflectionClass(T::class);
$rm = new ReflectionMethod(T::class, 'f');
$ro = new ReflectionObject(new T());
echo 'RC vars=', json_encode(get_object_vars($rc)), ' name=', var_export($rc->name, true), "\n";
echo 'RM vars=', json_encode(get_object_vars($rm)), ' name=', var_export($rm->name, true), ' class=', var_export($rm->class, true), "\n";
echo 'RO vars=', json_encode(get_object_vars($ro)), "\n";
// DateTime must stay empty from global scope.
echo 'DT vars=', json_encode(get_object_vars(new DateTime('now'))), "\n";
