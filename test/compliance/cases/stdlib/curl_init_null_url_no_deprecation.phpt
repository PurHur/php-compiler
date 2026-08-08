--TEST--
stdlib curl_init(null) — no null-to-string Deprecated; ?string stub (#28563, ext/curl/curl.stub.php)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $n, string $m) use (&$deps): bool {
    if (E_DEPRECATED === $n) {
        ++$deps;
    }
    return true;
});
$ch = curl_init(null);
$ch2 = curl_init('https://example.com');
$r = new ReflectionFunction('curl_init');
$p = $r->getParameters()[0];
echo 'null_handle=', (is_object($ch) || is_resource($ch)) ? 'yes' : 'no', "\n";
echo 'url_handle=', (is_object($ch2) || is_resource($ch2)) ? 'yes' : 'no', "\n";
echo 'type=', $p->hasType() ? (string) $p->getType() : 'NONE', "\n";
echo 'deps=', $deps, "\n";
?>
--EXPECT--
null_handle=yes
url_handle=yes
type=?string
deps=0
