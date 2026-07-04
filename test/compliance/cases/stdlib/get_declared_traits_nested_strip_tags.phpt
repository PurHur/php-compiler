--TEST--
Regression: get_declared_traits() nested in probe after strip_tags stmt call (#15846, ext/standard/basic_functions.c)
--FILE--
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
--EXPECT--
declared_traits_has: false
