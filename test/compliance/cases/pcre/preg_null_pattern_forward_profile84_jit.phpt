--TEST--
PCRE null $pattern JIT: TypeError match/split/grep; soft replace on 8.4 (#20226, #21198)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no || E_WARNING === $no) {
        echo (E_DEPRECATED === $no ? 'DEP' : 'WARN'), "\n";
        return true;
    }
    return false;
});
foreach ([
    'preg_match' => static fn () => preg_match(null, 'x'),
    'preg_split' => static fn () => preg_split(null, 'x'),
    'preg_grep' => static fn () => preg_grep(null, ['x']),
    'preg_replace' => static fn () => preg_replace(null, 'b', 'a'),
] as $label => $factory) {
    try {
        $r = $factory();
        echo $label, ' OK ', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
?>
--EXPECT--
preg_match: preg_match(): Argument #1 ($pattern) must be of type string, null given
preg_split: preg_split(): Argument #1 ($pattern) must be of type string, null given
preg_grep: preg_grep(): Argument #1 ($pattern) must be of type string, null given
DEP
WARN
preg_replace OK NULL
