--TEST--
mb_strstr family Reflection string|false + ?string encoding (#28584, mbstring.stub.php)
--FILE--
<?php
foreach (['mb_strstr', 'mb_stristr', 'mb_strrchr', 'mb_strrichr'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none';
    foreach ($r->getParameters() as $p) {
        if ($p->getName() === 'encoding') {
            echo ' encoding=', $p->hasType() ? (string) $p->getType() : 'untyped';
            echo $p->isOptional() ? ' =opt' : '';
            echo $p->allowsNull() ? ' null' : '';
            echo $p->isDefaultValueAvailable() ? ' def='.var_export($p->getDefaultValue(), true) : '';
        }
    }
    echo "\n";
}
echo 'miss=', var_export(mb_strstr('abc', 'z'), true), "\n";
echo 'hit=', var_export(mb_strstr('abc', 'b', encoding: null), true), "\n";
?>
--EXPECT--
mb_strstr ret=string|false encoding=?string =opt null def=NULL
mb_stristr ret=string|false encoding=?string =opt null def=NULL
mb_strrchr ret=string|false encoding=?string =opt null def=NULL
mb_strrichr ret=string|false encoding=?string =opt null def=NULL
miss=false
hit='bc'
