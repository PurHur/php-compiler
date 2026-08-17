--TEST--
curl_getinfo() Reflection CurlHandle $handle, ?int $option = null → mixed (#28369, curl.stub.php)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
$r = new ReflectionFunction('curl_getinfo');
echo 'arity=', $r->getNumberOfParameters(), "\n";
echo 'required=', $r->getNumberOfRequiredParameters(), "\n";
$ps = [];
foreach ($r->getParameters() as $p) {
    $t = $p->hasType() ? (string) $p->getType() . ' ' : '';
    $piece = $t . '$' . $p->getName();
    if ($p->isDefaultValueAvailable()) {
        $piece .= ' = ' . var_export($p->getDefaultValue(), true);
    }
    $ps[] = $piece;
}
echo 'curl_getinfo(', implode(', ', $ps), ')';
echo $r->hasReturnType() ? (': ' . (string) $r->getReturnType()) : '';
echo "\n";

$ch = curl_init();
$info = curl_getinfo(handle: $ch);
echo 'named_ok=', is_array($info) ? '1' : '0', "\n";
echo 'named_null_opt=', is_array(curl_getinfo(handle: $ch, option: null)) ? '1' : '0', "\n";
try {
    curl_getinfo(handle: 'x');
    echo "named_bad=ok\n";
} catch (TypeError $e) {
    echo 'named_bad=', $e->getMessage(), "\n";
}
curl_close($ch);
?>
--EXPECT--
arity=2
required=1
curl_getinfo(CurlHandle $handle, ?int $option = NULL): mixed
named_ok=1
named_null_opt=1
named_bad=curl_getinfo(): Argument #1 ($handle) must be of type CurlHandle, string given
