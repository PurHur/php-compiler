--TEST--
stdlib sprintf() positional * width/precision JIT/AOT (#22834, ext/standard/formatted_print.c)
--FILE--
<?php
declare(strict_types=1);

function positional_star_sprintf(): void {
    echo json_encode(sprintf('%2$*1$s', 5, 'z')), "\n";
    echo json_encode(sprintf('%1$.*2$s', 'abcdef', 3)), "\n";
    echo json_encode(sprintf('%3$*1$.*2$s', 8, 3, 'abcdef')), "\n";
    echo json_encode(sprintf('%2$*1$d', 5, 42)), "\n";
}
positional_star_sprintf();
--EXPECT--
"    z"
"abc"
"     abc"
"   42"
