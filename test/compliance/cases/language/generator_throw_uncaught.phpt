--TEST--
Generator throw() propagates when yield is not in try/catch (#167)
--FILE--
<?php
function gen(): Generator {
    yield 1;
}
$g = gen();
$g->rewind();
try {
    $g->throw(new Exception('propagate'));
    echo "no\n";
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
echo $g->valid() ? "valid\n" : "closed\n";
--EXPECT--
propagate
closed
