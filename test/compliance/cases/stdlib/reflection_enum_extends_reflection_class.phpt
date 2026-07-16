--TEST--
stdlib ReflectionEnum extends ReflectionClass — inherited APIs (#19740, ext/reflection/php_reflection.c)
--FILE--
<?php
enum E {
    case A;
}
$re = new ReflectionEnum(E::class);
echo 'instanceof_ReflectionClass=', $re instanceof ReflectionClass ? 'yes' : 'no', "\n";
echo 'parent=', var_export(get_parent_class($re), true), "\n";
echo 'isEnum=', var_export($re->isEnum(), true), "\n";
echo 'getShortName=', $re->getShortName(), "\n";
echo 'getName=', $re->getName(), "\n";
$methods = $re->getMethods();
echo 'getMethods=', is_array($methods) ? count($methods) : 'bad', "\n";
foreach ($methods as $m) {
    echo 'method=', $m->getName(), "\n";
}
$consts = $re->getConstants();
echo 'has_A=', array_key_exists('A', $consts) ? '1' : '0', "\n";
$attrs = $re->getAttributes();
echo 'getAttributes=', is_array($attrs) ? count($attrs) : 'bad', "\n";
--EXPECT--
instanceof_ReflectionClass=yes
parent='ReflectionClass'
isEnum=true
getShortName=E
getName=E
getMethods=1
method=cases
has_A=1
getAttributes=0
