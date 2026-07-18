--TEST--
PCRE preg_match/split/grep/replace null $pattern TypeError on 8.4 forward profile JIT (#20226)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'preg_match' => static fn () => preg_match(null, 'x'),
    'preg_split' => static fn () => preg_split(null, 'x'),
    'preg_grep' => static fn () => preg_grep(null, ['x']),
    'preg_replace' => static fn () => preg_replace(null, 'b', 'a'),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
?>
--EXPECT--
preg_match: preg_match(): Argument #1 ($pattern) must be of type string, null given
preg_split: preg_split(): Argument #1 ($pattern) must be of type string, null given
preg_grep: preg_grep(): Argument #1 ($pattern) must be of type string, null given
preg_replace: preg_replace(): Argument #1 ($pattern) must be of type array|string, null given
