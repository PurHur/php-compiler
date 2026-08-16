--TEST--
is_* / is_callable Reflection mixed $value + bool (VM, issue #28312 / #30242, type.stub.php)
--FILE--
<?php
$fns = [
    'is_numeric', 'is_string', 'is_int', 'is_integer', 'is_float', 'is_double',
    'is_bool', 'is_null', 'is_array', 'is_object', 'is_resource', 'is_scalar', 'is_callable',
];
foreach ($fns as $f) {
    $rf = new ReflectionFunction($f);
    $bits = [];
    foreach ($rf->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() : 'NONE';
        $def = $p->isDefaultValueAvailable() ? '='.var_export($p->getDefaultValue(), true) : '';
        $bits[] = $p->getName().':'.$t.($p->isPassedByReference() ? '&' : '').$def;
    }
    $ret = $rf->hasReturnType() ? (string) $rf->getReturnType() : 'NONE';
    echo $f, "\t", implode(',', $bits), "\t→\t", $ret, "\n";
}
echo 'is_int_named=', var_export(is_int(value: 1), true), "\n";
echo 'is_callable_omit=', var_export(is_callable('strlen'), true), "\n";
?>
--EXPECT--
is_numeric	value:mixed	→	bool
is_string	value:mixed	→	bool
is_int	value:mixed	→	bool
is_integer	value:mixed	→	bool
is_float	value:mixed	→	bool
is_double	value:mixed	→	bool
is_bool	value:mixed	→	bool
is_null	value:mixed	→	bool
is_array	value:mixed	→	bool
is_object	value:mixed	→	bool
is_resource	value:mixed	→	bool
is_scalar	value:mixed	→	bool
is_callable	value:mixed,syntax_only:bool=false,callable_name:NONE&=NULL	→	bool
is_int_named=true
is_callable_omit=true
