--TEST--
stdlib URL/base64 soft-null on 8.4 JIT — base64_*/url*encode/parse_url (#21188)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
        return true;
    }
    return false;
});
foreach (['base64_encode','base64_decode','rawurlencode','rawurldecode','urlencode','urldecode'] as $f) {
    try {
        $r = $f(null);
        echo $f, ' OK ', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $f, ' ', get_class($e), "\n";
    }
}
try {
    $r = parse_url(null);
    echo 'parse_url OK ', var_export($r, true), "\n";
} catch (Throwable $e) {
    echo 'parse_url ', get_class($e), "\n";
}
?>
--EXPECT--
DEP
base64_encode OK ''
DEP
base64_decode OK ''
DEP
rawurlencode OK ''
DEP
rawurldecode OK ''
DEP
urlencode OK ''
DEP
urldecode OK ''
DEP
parse_url OK array (
  'path' => '',
)
