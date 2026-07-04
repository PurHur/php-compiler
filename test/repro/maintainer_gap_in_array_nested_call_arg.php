<?php
declare(strict_types=1);

function probe(string $label, mixed $result): void
{
    echo $label . ': ' . (is_bool($result) ? ($result ? 'true' : 'false') : json_encode($result)) . "\n";
}

strip_tags('<p>x</p>', '<p>');
get_defined_constants(true);
$assigned = in_array('Traversable', get_declared_traits(), true);
probe('assigned', $assigned);
probe('nested_call', in_array('Traversable', get_declared_traits(), true));
