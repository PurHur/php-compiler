--TEST--
iconv_strlen/substr/strpos/strrpos JIT null TypeError on 8.4 forward profile (#20208, ext/iconv/iconv.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    'strlen' => static fn () => iconv_strlen(null),
    'substr' => static fn () => iconv_substr(null, 0, 1),
    'strpos' => static fn () => iconv_strpos(null, 'a'),
    'strrpos' => static fn () => iconv_strrpos(null, 'a'),
    'needle' => static fn () => iconv_strpos('ab', null),
] as $label => $factory) {
    try {
        $factory();
        echo "$label: uncaught\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
?>
--EXPECT--
strlen: iconv_strlen(): Argument #1 ($string) must be of type string, null given
substr: iconv_substr(): Argument #1 ($string) must be of type string, null given
strpos: iconv_strpos(): Argument #1 ($haystack) must be of type string, null given
strrpos: iconv_strrpos(): Argument #1 ($haystack) must be of type string, null given
needle: iconv_strpos(): Argument #2 ($needle) must be of type string, null given
