--TEST--
stdlib Z_PARAM_STR/LONG null — coerce vs TypeError on 8.4 forward profile (#19161/#19309/#19318/#19319)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'dirname' => static fn () => dirname(null),
    'explode' => static fn () => explode(',', null),
    'ord' => static fn () => ord(null),
    'chr' => static fn () => chr(null),
    'parse_url' => static fn () => parse_url(null),
] as $label => $factory) {
    try {
        $result = $factory();
        echo "$label: ";
        var_export($result);
        echo "\n";
    } catch (TypeError $e) {
        echo "$label: ", $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
dirname: dirname(): Argument #1 ($path) must be of type string, null given
explode: explode(): Argument #2 ($string) must be of type string, null given
ord: ord(): Argument #1 ($character) must be of type string, null given
chr: chr(): Argument #1 ($codepoint) must be of type int, null given
parse_url: array (
  'path' => '',
)
