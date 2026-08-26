<?php
// AOT: strtr(array) must apply replacements — NestedJIT isset walk was a no-op (#35038).
echo strtr('hello', ['h' => 'H', 'e' => 'E']), PHP_EOL;
echo strtr('ab', ['a' => 'x', 'b' => 'y']), PHP_EOL;
echo strtr('abc', ['ab' => 'XY', 'c' => 'Z']), PHP_EOL;
echo strtr('noop', ['x' => 'y']), PHP_EOL;
echo strtr('', ['a' => 'b']) === '' ? "empty\n" : "nonempty\n";
