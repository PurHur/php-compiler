<?php
/**
 * AOT printf/sprintf sequential star width on %s (#34969).
 * php-src: ext/standard/formatted_print.c — * width consumes next arg as long.
 */
declare(strict_types=1);

echo json_encode(sprintf('%*s', 5, 'x')), "\n";
printf("%*s\n", 5, 'x');
echo json_encode(sprintf('%.*s', 2, 'hello')), "\n";
echo json_encode(sprintf('%*.*s', 6, 3, 'abcdef')), "\n";
printf("%*d\n", 5, 42);
