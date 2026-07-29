--TEST--
stdlib parse_url($url, null) soft-null DEP+PHP_URL_SCHEME (#24942, ext/standard/url.c)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    if (E_DEPRECATED === $no) {
        echo $str, "\n";
        return true;
    }
    return false;
});
// Use a variable so soft-null runs at runtime (literal null may fold before the handler).
$component = null;
echo var_export(parse_url('http://example.com/x', $component), true), "\n";
echo var_export(parse_url(url: 'http://example.com/x', component: $component), true), "\n";
?>
--EXPECT--
parse_url(): Passing null to parameter #2 ($component) of type int is deprecated
'http'
parse_url(): Passing null to parameter #2 ($component) of type int is deprecated
'http'
