--TEST--
stdlib error_log Reflection/named params (#23341, basic_functions.stub.php)
--FILE--
<?php
$r = new ReflectionFunction('error_log');
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ' ';
}
echo "\n";
var_export(error_log(message: 'parity', message_type: 0));
echo "\n";
var_export(error_log(message: 'parity', message_type: 0, additional_headers: 'X-Test: 1'));
echo "\n";
try {
    error_log(message: 'parity', message_type: 0, extra_headers: 'X-Test: 1');
    echo "legacy extra_headers ok\n";
} catch (Throwable $e) {
    echo 'legacy extra_headers ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
message message_type destination additional_headers 
true
true
legacy extra_headers ERR=Error: Unknown named parameter $extra_headers
