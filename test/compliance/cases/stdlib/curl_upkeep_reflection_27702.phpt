--TEST--
curl_upkeep() Reflection CurlHandle $handle → bool + named handle (#27702, curl.stub.php)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
$r = new ReflectionFunction('curl_upkeep');
echo 'arity=', $r->getNumberOfParameters(), "\n";
echo 'required=', $r->getNumberOfRequiredParameters(), "\n";
$ps = [];
foreach ($r->getParameters() as $p) {
    $t = $p->hasType() ? (string) $p->getType() . ' ' : '';
    $ps[] = $t . '$' . $p->getName();
}
echo 'curl_upkeep(', implode(', ', $ps), ')';
echo $r->hasReturnType() ? (': ' . (string) $r->getReturnType()) : '';
echo "\n";

$ch = curl_init();
echo 'named_ok=', curl_upkeep(handle: $ch) ? '1' : '0', "\n";
try {
    curl_upkeep(handle: 'x');
    echo "named_bad=ok\n";
} catch (TypeError $e) {
    echo 'named_bad=', $e->getMessage(), "\n";
}
curl_close($ch);
?>
--EXPECT--
arity=1
required=1
curl_upkeep(CurlHandle $handle): bool
named_ok=1
named_bad=curl_upkeep(): Argument #1 ($handle) must be of type CurlHandle, string given
