--TEST--
Generator yield from non-traversable throws TypeError (issue #4338)
--FILE--
<?php
function gen() {
    yield from 1;
}
try {
    foreach (gen() as $_) {
    }
    echo "no throw\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Can only use yield from on Traversable|array
