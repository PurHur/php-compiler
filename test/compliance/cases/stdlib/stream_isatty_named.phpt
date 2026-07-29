--TEST--
stream_isatty Reflection stream param + named stream: (issue #24609, php-src basic_functions.stub.php)
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
try {
    stream_isatty(fp: STDIN);
    echo "fp accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
1
stream
named_ok
Unknown named parameter $fp
