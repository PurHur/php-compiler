--TEST--
curl_share_init_persistent() Reflection array $share_options → CurlSharePersistentHandle (#27711)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
if (!function_exists('curl_share_init_persistent')) {
    die('skip curl_share_init_persistent requires PHP_COMPILER_PROFILE=8.5');
}
$r = new ReflectionFunction('curl_share_init_persistent');
echo 'arity=', $r->getNumberOfParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo 'param=', $p->getName(), ':', ($p->hasType() ? (string) $p->getType() : '?'), "\n";
}
echo 'ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'none', "\n";
$h = curl_share_init_persistent(share_options: [CURL_LOCK_DATA_DNS]);
echo 'named_ok=', get_debug_type($h), "\n";
?>
--EXPECT--
arity=1
param=share_options:array
ret=CurlSharePersistentHandle
named_ok=CurlSharePersistentHandle
