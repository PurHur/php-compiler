--TEST--
getprotobyname/getprotobynumber Reflection protocol match php-src stub (#24562, basic_functions.stub.php)
--FILE--
<?php
foreach (['getprotobyname', 'getprotobynumber'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo "== $fn ==\n";
    foreach ($r->getParameters() as $p) {
        echo $p->getName(), "\n";
    }
}
$a = getprotobyname(protocol: 'tcp');
$b = getprotobynumber(protocol: 6);
echo 'named_bound=', var_export($a !== null || $a === false, true), ' ', var_export($b !== null || $b === false, true), "\n";
?>
--EXPECT--
== getprotobyname ==
protocol
== getprotobynumber ==
protocol
named_bound=true true
