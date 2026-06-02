--TEST--
Generator try/catch resume via MCJIT (issue #4069)
--FILE--
<?php
function gen() {
    try {
        yield 1;
        throw new Exception('boom');
        yield 2;
    } catch (Exception $e) {
        yield 'caught:' . $e->getMessage();
    }
}
foreach (gen() as $v) {
    echo $v, "\n";
}
--EXPECT--
1
caught:boom
