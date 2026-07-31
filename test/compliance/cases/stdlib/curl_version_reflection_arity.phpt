--TEST--
stdlib curl_version Reflection arity 0 — no $version (#25585, ext/curl/curl.stub.php)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
$r = new ReflectionFunction('curl_version');
echo 'req=', $r->getNumberOfRequiredParameters(), ' num=', $r->getNumberOfParameters(), "\n";
echo 'ret=', (string) $r->getReturnType(), "\n";
echo 'params=', count($r->getParameters()), "\n";
try {
    curl_version(0);
    echo "positional_ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
try {
    curl_version(version: 0);
    echo "named_version_ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
$v = curl_version();
echo is_array($v) && isset($v['version']) ? "zero_ok\n" : "zero_bad\n";
?>
--EXPECT--
req=0 num=0
ret=array|false
params=0
ArgumentCountError
Error
zero_ok
