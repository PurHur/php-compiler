--TEST--
stdlib iconv_strlen/iconv_substr/iconv_strpos/iconv_strrpos ISO-8859-1 helpers (#6247, ext/iconv/iconv.c)
--FILE--
<?php
$iso = "\xE9\xE9";
echo iconv_strlen($iso, 'ISO-8859-1'), "\n";
echo bin2hex(iconv_substr($iso, 0, 1, 'ISO-8859-1')), "\n";
echo var_export(iconv_strpos($iso, "\xE9", 0, 'ISO-8859-1')), "\n";
echo var_export(iconv_strrpos($iso, "\xE9", 'ISO-8859-1')), "\n";
foreach (['iconv_strlen', 'iconv_strpos', 'iconv_substr', 'iconv_strrpos'] as $fn) {
    echo $fn, ':', function_exists($fn) ? 'yes' : 'no', "\n";
}
?>
--EXPECT--
2
e9
0
1
iconv_strlen:yes
iconv_strpos:yes
iconv_substr:yes
iconv_strrpos:yes
