--TEST--
Regression: in_array() nested call as function argument returns bool not null (#16013, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

function probe(string $label, mixed $result): void
{
    echo $label . ': ' . (is_bool($result) ? ($result ? 'true' : 'false') : json_encode($result)) . "\n";
}

$assigned = in_array(1, ['a' => 1, 'b' => 2], true);
probe('assigned', $assigned);
probe('nested_call', in_array(1, ['a' => 1, 'b' => 2], true));

--EXPECT--
assigned: true
nested_call: true
