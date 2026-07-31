--TEST--
mysqli_sql_exception::getSqlState() + protected $sqlstate (#22456, ext/mysqli/mysqli.stub.php)
--ENV--
PHP_COMPILER_ENABLE_MYSQLI=1
--FILE--
<?php
echo 'getSqlState=', (int) method_exists('mysqli_sql_exception', 'getSqlState'), "\n";
echo 'sqlstate_prop=', (int) property_exists('mysqli_sql_exception', 'sqlstate'), "\n";
$e = new mysqli_sql_exception('duplicate key', 1062);
echo 'code=', $e->getCode(), "\n";
echo 'state=', $e->getSqlState(), "\n";
$r = new ReflectionProperty('mysqli_sql_exception', 'sqlstate');
echo 'vis=', $r->isProtected() ? 'protected' : ($r->isPublic() ? 'public' : 'other'), "\n";
echo 'default=', var_export($r->getDefaultValue(), true), "\n";
?>
--EXPECT--
getSqlState=1
sqlstate_prop=1
code=1062
state=00000
vis=protected
default='00000'
