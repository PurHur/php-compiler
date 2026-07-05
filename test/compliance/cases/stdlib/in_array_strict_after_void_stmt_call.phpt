--TEST--
Regression: in_array()/array_search() strict after void stmt call — boolean not NULL (#16253, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

function probe(string $label, mixed $result): void
{
    echo $label . ': ' . (is_bool($result) ? ($result ? 'true' : 'false') : json_encode($result)) . "\n";
}

strlen('probe');
probe('in_array_strict', in_array('stdClass', get_declared_classes(), true));

strlen('probe');
probe('array_search_strict', array_search('Traversable', get_declared_traits(), true) !== false);
--EXPECT--
in_array_strict: true
array_search_strict: false
