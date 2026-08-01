--TEST--
password_get_info/password_needs_rehash named hash/algo + Reflection (VM, issue #23292)
--FILE--
<?php
$h = password_hash('secret', PASSWORD_DEFAULT);
$info = password_get_info(hash: $h);
echo isset($info['algoName']) ? "info_ok\n" : "info_fail\n";
echo password_needs_rehash(hash: $h, algo: PASSWORD_DEFAULT) ? "rehash_yes\n" : "rehash_no\n";
$rf = new ReflectionFunction('password_get_info');
echo 'get_info_ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'NONE', PHP_EOL;
foreach ($rf->getParameters() as $p) {
    echo 'get_info:', $p->getName(), ':', $p->hasType() ? (string) $p->getType() : 'NONE', PHP_EOL;
}
$rf = new ReflectionFunction('password_needs_rehash');
echo 'needs_rehash_ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'NONE', PHP_EOL;
foreach ($rf->getParameters() as $p) {
    echo 'needs_rehash:', $p->getName(),
        $p->isOptional() ? '=' : '',
        ':', $p->hasType() ? (string) $p->getType() : 'NONE', PHP_EOL;
}
--EXPECT--
info_ok
rehash_no
get_info_ret=array
get_info:hash:string
needs_rehash_ret=bool
needs_rehash:hash:string
needs_rehash:algo:string|int|null
needs_rehash:options=:array
