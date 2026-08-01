<?php
// #23693 — hebrev Reflection param names + named string: (ext/standard/string.stub.php)
$names = [];
foreach ((new ReflectionFunction('hebrev'))->getParameters() as $p) {
    $names[] = $p->getName()
        .($p->isOptional() ? '=' : '')
        .($p->hasType() ? ':'.(string) $p->getType() : '');
}
$ret = (new ReflectionFunction('hebrev'))->hasReturnType()
    ? (string) (new ReflectionFunction('hebrev'))->getReturnType()
    : 'NONE';

$named = hebrev(string: 'abc');
$namedMax = hebrev(string: 'abc', max_chars_per_line: 0);

$ok = ['string:string', 'max_chars_per_line=:int'] === $names
    && 'string' === $ret
    && 'abc' === $named
    && 'abc' === $namedMax;
echo $ok ? "ok\n" : "fail\n";
echo 'names=', implode(',', $names), ' ret=', $ret, "\n";
echo 'named=', $named, "\n";
echo 'named_max=', $namedMax, "\n";
