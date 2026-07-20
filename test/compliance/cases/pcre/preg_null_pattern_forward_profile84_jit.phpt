--TEST--
PCRE null $pattern JIT soft-null DEP+WARN+false on 8.4 (#21479, reverts #20226; soft replace #21198)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
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
    'preg_match_all' => static fn () => preg_match_all(null, 'x'),
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
DEP
WARN
preg_match OK false
DEP
WARN
preg_match_all OK false
DEP
WARN
preg_split OK false
DEP
WARN
preg_grep OK false
DEP
WARN
preg_replace OK NULL
