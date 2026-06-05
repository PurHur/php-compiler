--TEST--
Generator MCJIT yield object/array expressions (issue #4981)
--FILE--
<?php
function gen(): Generator {
    yield ['k' => 1];
    yield new stdClass;
}
foreach (gen() as $v) {
    echo is_array($v) ? 'arr' : get_class($v), "\n";
}
--EXPECT--
arr
stdClass
