--TEST--
curl_multi_get_handles lists attached easy handles (#20520, PHP 8.5, ext/curl/multi.c)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::advertisesCurlMultiGetHandles()) {
    die('skip curl_multi_get_handles requires PHP_COMPILER_PROFILE=8.5');
}
if (!function_exists('curl_multi_init') && !class_exists('PHPCompiler\\ext\\curl\\VmCurlNative', false)) {
    // Host may not have loaded curl yet; VM path registers via Module when libcurl FFI works.
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
declare(strict_types=1);

echo 'exists=', function_exists('curl_multi_get_handles') ? 'yes' : 'no', "\n";

$mh = curl_multi_init();
$empty = curl_multi_get_handles($mh);
echo 'empty=', count($empty), "\n";

$f = tempnam(sys_get_temp_dir(), 'curlm_gh_');
file_put_contents($f, "ok\n");
$ch = curl_init('file://' . $f);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
echo 'add=', curl_multi_add_handle($mh, $ch), "\n";

$hs = curl_multi_get_handles($mh);
echo 'count=', count($hs), "\n";
echo 'same=', ($hs[0] === $ch) ? 'yes' : 'no', "\n";

curl_multi_remove_handle($mh, $ch);
$after = curl_multi_get_handles($mh);
echo 'after=', count($after), "\n";

try {
    curl_multi_get_handles(null);
    echo "null-type=ok\n";
} catch (TypeError $e) {
    echo "null-type=TypeError\n";
}

curl_multi_close($mh);
curl_close($ch);
@unlink($f);
?>
--EXPECT--
exists=yes
empty=0
add=0
count=1
same=yes
after=0
null-type=TypeError
