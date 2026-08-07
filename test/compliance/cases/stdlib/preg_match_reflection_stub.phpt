--TEST--
stdlib preg_match Reflection stub: untyped &$matches + int|false (#25580)
--FILE--
<?php
foreach (['preg_match', 'preg_match_all'] as $f) {
    $r = new ReflectionFunction($f);
    $bits = [];
    foreach ($r->getParameters() as $p) {
        $bits[] = $p->getName()
            . ':'
            . ($p->getType() ?: 'none')
            . ($p->isPassedByReference() ? '&' : '');
    }
    echo $f, ' ', implode('|', $bits), ' ret=', (string) $r->getReturnType(), "\n";
}
?>
--EXPECT--
preg_match pattern:string|subject:string|matches:none&|flags:int|offset:int ret=int|false
preg_match_all pattern:string|subject:string|matches:none&|flags:int|offset:int ret=int|false
