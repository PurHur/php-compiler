--TEST--
PCRE preg_split/match_all/replace_callback* null $subject soft-null DEP+coerce on 8.4 JIT (#21318)
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
$cb = static function ($m) {
    return 'x';
};
$patterns = ['/x/' => $cb];
foreach ([
    ['preg_split', static function () {
        return preg_split('/x/', null);
    }],
    ['preg_match_all', static function () {
        return preg_match_all('/x/', null);
    }],
    ['preg_replace_callback', static function () use ($cb) {
        return preg_replace_callback('/x/', $cb, null);
    }],
    ['preg_replace_callback_array', static function () use ($patterns) {
        return preg_replace_callback_array($patterns, null);
    }],
] as [$f, $call]) {
    try {
        $r = $call();
        echo $f, ' OK ', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $f, ' ', get_class($e), "\n";
    }
}
?>
--EXPECT--
DEP
preg_split OK array (
  0 => '',
)
DEP
preg_match_all OK 0
DEP
preg_replace_callback OK ''
DEP
preg_replace_callback_array OK ''
