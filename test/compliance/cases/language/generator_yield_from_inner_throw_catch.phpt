--TEST--
Generator yield from inner throw caught by outer try/catch (issue #32102)
--FILE--
<?php
function inner() {
    yield 1;
    throw new Exception('e');
}
function outer() {
    try {
        yield from inner();
    } catch (Exception $e) {
        yield 'c:'.$e->getMessage();
    }
}
foreach (outer() as $v) {
    echo $v, "\n";
}
echo "done\n";
--EXPECT--
1
c:e
done
