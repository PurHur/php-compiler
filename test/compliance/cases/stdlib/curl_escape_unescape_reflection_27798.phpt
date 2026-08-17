--TEST--
curl_escape/curl_unescape Reflection CurlHandle + string → string|false (#27798, curl.stub.php)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
foreach (['curl_escape', 'curl_unescape'] as $f) {
    $r = new ReflectionFunction($f);
    echo 'arity=', $r->getNumberOfParameters(), "\n";
    echo 'required=', $r->getNumberOfRequiredParameters(), "\n";
    $ps = [];
    foreach ($r->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() . ' ' : '';
        $ps[] = $t . '$' . $p->getName();
    }
    echo $f, '(', implode(', ', $ps), ')';
    echo $r->hasReturnType() ? (': ' . (string) $r->getReturnType()) : '';
    echo "\n";
}

$ch = curl_init();
echo 'escape=', curl_escape(handle: $ch, string: 'a b'), "\n";
echo 'unescape=', curl_unescape(handle: $ch, string: 'a%20b'), "\n";
try {
    curl_escape(handle: 'x', string: 'y');
    echo "named_bad=ok\n";
} catch (TypeError $e) {
    echo 'named_bad=', $e->getMessage(), "\n";
}
curl_close($ch);
?>
--EXPECT--
arity=2
required=2
curl_escape(CurlHandle $handle, string $string): string|false
arity=2
required=2
curl_unescape(CurlHandle $handle, string $string): string|false
escape=a%20b
unescape=a b
named_bad=curl_escape(): Argument #1 ($handle) must be of type CurlHandle, string given
