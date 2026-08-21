--TEST--
stdlib request_parse_body Reflection/named options (#23878, http.stub.php)
--FILE--
<?php
if (!function_exists('request_parse_body')) {
    echo "request_parse_body MISSING\n";
    return;
}
$r = new ReflectionFunction('request_parse_body');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
    $t = $p->getType();
    echo $p->getName(),
        ' type=', $t ? (string) $t : '(none)',
        ' optional=', (int) $p->isOptional(),
        ' allowsNull=', (int) $p->allowsNull(),
        "\n";
    if ($p->isDefaultValueAvailable()) {
        echo 'default=', var_export($p->getDefaultValue(), true), "\n";
    }
}
$rt = $r->getReturnType();
echo 'return=', $rt ? (string) $rt : '(none)', "\n";
echo 'argc=', $r->getNumberOfParameters(), ' req=', $r->getNumberOfRequiredParameters(), "\n";
try {
    request_parse_body(options: []);
    echo "named_ok\n";
} catch (RequestParseBodyException $e) {
    echo 'named_bound=', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'named_ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
options type=?array optional=1 allowsNull=1
default=NULL
return=array
argc=1 req=0
named_bound=Request does not provide a content type
