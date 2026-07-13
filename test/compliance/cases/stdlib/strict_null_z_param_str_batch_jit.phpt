--TEST--
stdlib Z_PARAM_STR/int builtins JIT — null TypeError under declare(strict_types=1) (#18631 #18633 #18634 #18626)
--JIT--
--FILE--
<?php
declare(strict_types=1);

foreach (['urlencode', 'rawurlencode', 'htmlspecialchars_decode', 'trim', 'ltrim', 'rtrim', 'chop'] as $fn) {
    try {
        $fn(null);
        echo "$fn: uncaught\n";
    } catch (TypeError $e) {
        echo $fn.': '.$e->getMessage()."\n";
    }
}
try {
    get_html_translation_table(null);
    echo "get_html_translation_table: uncaught\n";
} catch (TypeError $e) {
    echo 'get_html_translation_table: '.$e->getMessage()."\n";
}
?>
--EXPECT--
urlencode: urlencode(): Argument #1 ($string) must be of type string, null given
rawurlencode: rawurlencode(): Argument #1 ($string) must be of type string, null given
htmlspecialchars_decode: htmlspecialchars_decode(): Argument #1 ($string) must be of type string, null given
trim: trim(): Argument #1 ($string) must be of type string, null given
ltrim: ltrim(): Argument #1 ($string) must be of type string, null given
rtrim: rtrim(): Argument #1 ($string) must be of type string, null given
chop: chop(): Argument #1 ($string) must be of type string, null given
get_html_translation_table: get_html_translation_table(): Argument #1 ($table) must be of type int, null given
