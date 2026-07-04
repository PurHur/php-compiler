<?php

declare(strict_types=1);

function probe(string $label, mixed $result): void
{
    echo $label . ': ' . (is_bool($result) ? ($result ? 'true' : 'false') : json_encode($result)) . "\n";
}

strip_tags('<p>x</p>', '<p>');
$c = get_defined_constants(true);
probe('declared_traits_has', in_array('Traversable', get_declared_traits(), true));

class CV
{
    public static int $s = 1;
}
