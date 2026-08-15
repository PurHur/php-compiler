--TEST--
iconv_strpos/iconv_strrpos Reflection ?string $encoding → int|false (VM, issue #28586)
--FILE--
<?php
foreach (['iconv_strpos', 'iconv_strrpos'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
    foreach ($r->getParameters() as $p) {
        if ($p->getName() === 'encoding') {
            echo '  encoding=', $p->hasType() ? (string) $p->getType() : 'untyped',
                ' allows_null=', $p->allowsNull() ? '1' : '0',
                $p->isOptional() ? ' =opt' : '',
                "\n";
        }
    }
}
echo 'pos=', var_export(iconv_strpos(haystack: 'abc', needle: 'b', offset: 0, encoding: null), true), "\n";
echo 'rpos=', var_export(iconv_strrpos(haystack: 'abcb', needle: 'b', encoding: null), true), "\n";
echo 'miss=', var_export(iconv_strpos('abc', 'z', 0, 'UTF-8'), true), "\n";
?>
--EXPECT--
iconv_strpos ret=int|false
  encoding=?string allows_null=1 =opt
iconv_strrpos ret=int|false
  encoding=?string allows_null=1 =opt
pos=1
rpos=3
miss=false
