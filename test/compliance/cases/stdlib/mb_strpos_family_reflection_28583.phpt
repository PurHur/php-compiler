--TEST--
mb_strpos family Reflection int|false + ?string encoding (#28583, mbstring.stub.php)
--FILE--
<?php
foreach (['mb_strpos', 'mb_strrpos', 'mb_stripos', 'mb_strripos'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none';
    foreach ($r->getParameters() as $p) {
        if ($p->getName() === 'encoding') {
            echo ' encoding=', $p->hasType() ? (string) $p->getType() : 'untyped';
            echo $p->isOptional() ? ' =opt' : '';
            echo $p->allowsNull() ? ' null' : '';
        }
    }
    echo "\n";
}
echo 'miss=', var_export(mb_strpos('abc', 'z'), true), "\n";
echo 'hit=', var_export(mb_strpos('abc', 'b', encoding: null), true), "\n";
?>
--EXPECT--
mb_strpos ret=int|false encoding=?string =opt null
mb_strrpos ret=int|false encoding=?string =opt null
mb_stripos ret=int|false encoding=?string =opt null
mb_strripos ret=int|false encoding=?string =opt null
miss=false
hit=1
