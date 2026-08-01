<?php
// #23292 — password_get_info / password_needs_rehash Reflection + named args
$infoNames = [];
foreach ((new ReflectionFunction('password_get_info'))->getParameters() as $p) {
    $infoNames[] = $p->getName()
        .($p->hasType() ? ':'.(string) $p->getType() : '');
}
$infoRet = (new ReflectionFunction('password_get_info'))->hasReturnType()
    ? (string) (new ReflectionFunction('password_get_info'))->getReturnType()
    : 'NONE';

$rehashNames = [];
foreach ((new ReflectionFunction('password_needs_rehash'))->getParameters() as $p) {
    $rehashNames[] = $p->getName()
        .($p->isOptional() ? '=' : '')
        .($p->hasType() ? ':'.(string) $p->getType() : '');
}
$rehashRet = (new ReflectionFunction('password_needs_rehash'))->hasReturnType()
    ? (string) (new ReflectionFunction('password_needs_rehash'))->getReturnType()
    : 'NONE';

$h = password_hash('x', PASSWORD_DEFAULT);
$namedInfo = password_get_info(hash: $h);
$namedRehash = password_needs_rehash(hash: $h, algo: PASSWORD_DEFAULT);

$ok = ['hash:string'] === $infoNames
    && 'array' === $infoRet
    && ['hash:string', 'algo:string|int|null', 'options=:array'] === $rehashNames
    && 'bool' === $rehashRet
    && is_array($namedInfo)
    && isset($namedInfo['algoName'])
    && is_bool($namedRehash);
echo $ok ? "ok\n" : "fail\n";
echo 'info_names=', implode(',', $infoNames), ' ret=', $infoRet, "\n";
echo 'rehash_names=', implode(',', $rehashNames), ' ret=', $rehashRet, "\n";
echo 'named_info=', $namedInfo['algoName'] ?? 'MISSING', "\n";
echo 'named_rehash=', var_export($namedRehash, true), "\n";
