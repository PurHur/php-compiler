--TEST--
stdlib fnmatch() JIT — enum case pattern/filename TypeError (#5932)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'x'; }

foreach ([
    ['pattern', fn () => fnmatch(E::A, 'x')],
    ['filename', fn () => fnmatch('x', E::A)],
] as [$label, $call]) {
    try {
        $call();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo "$label: ", $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
pattern: fnmatch(): Argument #1 ($pattern) must be of type string, E given
filename: fnmatch(): Argument #2 ($filename) must be of type string, E given
