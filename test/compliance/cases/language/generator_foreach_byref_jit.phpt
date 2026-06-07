--TEST--
Generator foreach by-reference throws Exception under JIT (#4599)
--JIT--
--FILE--
<?php
function gen(): Generator {
    yield 'x';
}
try {
    foreach (gen() as &$v) {
        $v = 'Y';
    }
    echo "no error\n";
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
You can only iterate a generator by-reference if it declared that it yields by-reference
