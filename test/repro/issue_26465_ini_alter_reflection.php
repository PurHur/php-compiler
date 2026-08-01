<?php
// #26465 — ini_alter Reflection + named args match Zend ini_set alias stubs
$names = [];
foreach ((new ReflectionFunction('ini_alter'))->getParameters() as $p) {
    $names[] = $p->getName()
        .($p->hasType() ? ':'.(string) $p->getType() : '');
}
$ret = (new ReflectionFunction('ini_alter'))->hasReturnType()
    ? (string) (new ReflectionFunction('ini_alter'))->getReturnType()
    : 'NONE';

$named = null;
$namedErr = null;
try {
    $named = ini_alter(option: 'display_errors', value: '0');
} catch (Throwable $e) {
    $namedErr = get_class($e).':'.$e->getMessage();
}

$ok = ['option:string', 'value:string|int|float|bool|null'] === $names
    && 'string|false' === $ret
    && null === $namedErr
    && (is_string($named) || false === $named);
echo $ok ? "ok\n" : "fail\n";
echo 'names=', implode(',', $names), ' ret=', $ret, "\n";
echo 'named=', null !== $namedErr ? $namedErr : var_export($named, true), "\n";
