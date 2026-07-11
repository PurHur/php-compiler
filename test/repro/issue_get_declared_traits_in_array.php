<?php
declare(strict_types=1);

function probe(string $label, mixed $result): void {
    echo $label . ': ' . (is_bool($result) ? ($result ? 'true' : 'false') : json_encode($result)) . "\n";
}

probe('strip_tags_allow', strip_tags('<p>hi</p><b>x</b>', '<p>'));
$uu = convert_uuencode('Hello');
probe('convert_uudecode', convert_uudecode($uu));
parse_str('a=1&b=2', $out);
$c = get_defined_constants(true);
probe('declared_traits_has', in_array('Traversable', get_declared_traits(), true));

class CV { public static int $s = 1; }
