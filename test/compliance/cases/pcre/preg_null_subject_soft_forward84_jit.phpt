--TEST--
PCRE preg_match/preg_replace null $subject soft-null DEP+coerce on 8.4 JIT (#21198)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
        return true;
    }
    return false;
});
foreach ([
    ['preg_match', ['/a/', null]],
    ['preg_replace', ['/a/', 'b', null]],
] as [$f, $a]) {
    try {
        $r = $f(...$a);
        echo $f, ' OK ', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $f, ' ', get_class($e), "\n";
    }
}
?>
--EXPECT--
DEP
preg_match OK 0
DEP
preg_replace OK ''
