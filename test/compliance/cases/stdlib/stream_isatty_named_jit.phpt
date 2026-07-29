--TEST--
stream_isatty named stream: under JIT (issue #24609)
--JIT--
--FILE--
<?php
$rf = new ReflectionFunction('stream_isatty');
echo $rf->getNumberOfRequiredParameters(), "\n";
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), "\n";
}
$memory = fopen('php://memory', 'r+');
echo stream_isatty($memory) === stream_isatty(stream: $memory) ? 'named_ok' : 'named_mismatch', "\n";
fclose($memory);
?>
--EXPECT--
1
stream
named_ok
