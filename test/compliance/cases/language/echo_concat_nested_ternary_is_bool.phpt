--TEST--
language: echo concat prefix survives nested ?: with is_bool() (#14260, Zend/zend_execute.c)
--FILE--
<?php
function probe(string $label, mixed $result): void {
    echo $label . ': ' . (is_bool($result) ? ($result ? 'true' : 'false') : json_encode($result)) . "\n";
}
probe('bool_probe', false);
--EXPECT--
bool_probe: false
