--TEST--
iconv_substr Reflection string|false + ?int length + ?string encoding (VM, issue #28585)
--FILE--
<?php
$r = new ReflectionFunction('iconv_substr');
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
foreach ($r->getParameters() as $p) {
    if ($p->getName() === 'length' || $p->getName() === 'encoding') {
        echo $p->getName(), '=', $p->hasType() ? (string) $p->getType() : 'untyped',
            ' allows_null=', $p->allowsNull() ? '1' : '0',
            $p->isOptional() ? ' =opt' : '',
            $p->isDefaultValueAvailable() ? ' def='.var_export($p->getDefaultValue(), true) : '',
            "\n";
    }
}
echo 'named=', var_export(iconv_substr(string: 'abcdef', offset: 1, length: 2, encoding: 'UTF-8'), true), "\n";
echo 'null_len=', var_export(iconv_substr('abcdef', 1, length: null, encoding: 'UTF-8'), true), "\n";
echo 'null_enc=', var_export(iconv_substr('abcdef', 1, 2, encoding: null), true), "\n";
?>
--EXPECT--
ret=string|false
length=?int allows_null=1 =opt def=NULL
encoding=?string allows_null=1 =opt def=NULL
named='bc'
null_len='bcdef'
null_enc='bc'
