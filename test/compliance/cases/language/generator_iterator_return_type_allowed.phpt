--TEST--
Language: generator with Iterator return type and yield — allowed (#26467)
--FILE--
<?php
function gen(): Iterator {
    yield 1;
}
foreach (gen() as $v) {
    echo $v, "\n";
}
--EXPECT--
1
