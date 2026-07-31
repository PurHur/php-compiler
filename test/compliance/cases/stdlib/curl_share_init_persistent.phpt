--TEST--
curl_share_init_persistent() + CurlSharePersistentHandle (#20530, PHP 8.5, ext/curl/share.c)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::advertisesCurlShareInitPersistent()) {
    die('skip curl_share_init_persistent requires PHP_COMPILER_PROFILE=8.5');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
declare(strict_types=1);

echo 'exists=', function_exists('curl_share_init_persistent') ? 'yes' : 'no', "\n";
echo 'class=', class_exists('CurlSharePersistentHandle', false) ? 'yes' : 'no', "\n";

$sh1 = curl_share_init_persistent([CURL_LOCK_DATA_CONNECT, CURL_LOCK_DATA_DNS, CURL_LOCK_DATA_DNS]);
echo get_class($sh1), "\n";
$opts = $sh1->options;
echo 'options=', $opts[0], ',', $opts[1], "\n";

$sh2 = curl_share_init_persistent([CURL_LOCK_DATA_DNS, CURL_LOCK_DATA_CONNECT]);
echo 'reuse=', ($sh1 === $sh2) ? 'yes' : 'no', "\n";

$ch = curl_init('file:///etc/hosts');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SHARE, $sh1);
echo 'setopt-share=ok', "\n";
?>
--EXPECT--
exists=yes
class=yes
CurlSharePersistentHandle
options=3,5
reuse=yes
setopt-share=ok
