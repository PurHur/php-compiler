<?php
declare(strict_types=1);

function probe(string $label, mixed $result): void
{
    echo $label . ': ' . (is_bool($result) ? ($result ? 'true' : 'false') : json_encode($result)) . "\n";
}

strip_tags('<p>x</p>', '<p>');
get_defined_constants(true);
probe('array_search_nested', array_search('Traversable', get_declared_traits(), true) !== false);
