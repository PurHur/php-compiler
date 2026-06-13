--TEST--
stdlib basename()/dirname() — TypeError for wrong operand types (#4715, ext/standard)
--FILE--
<?php
$tests = [
    'basename_suffix' => function () {
        basename('/path', []);
    },
    'dirname_levels' => function () {
        dirname('/a/b/c', []);
    },
];
foreach ($tests as $label => $fn) {
    try {
        $fn();
        echo $label, ": uncaught\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
basename_suffix: TypeError: basename(): Argument #2 ($suffix) must be of type string, array given
dirname_levels: TypeError: dirname(): Argument #2 ($levels) must be of type int, array given
