--TEST--
Dom\TokenList $value write; no __toString (php-src stub; #26721, re-#24545)
--SKIPIF--
<?php
if (!class_exists('Dom\\TokenList') || !class_exists('Dom\\HTMLDocument')) {
    die('skip Dom\\TokenList / Dom\\HTMLDocument require PHP_COMPILER_PROFILE=8.4 (#26721)');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$html = Dom\HTMLDocument::createFromString(
    '<!DOCTYPE html><html><body><div class="a b" id="d"></div></body></html>',
    LIBXML_NOERROR
);
$cl = $html->getElementById('d')->classList;
echo get_class($cl), "\n";
echo $cl->value, "\n";
$cl->value = 'c d';
echo $cl->value, "\n";
echo $html->getElementById('d')->getAttribute('class'), "\n";
echo (int) method_exists($cl, '__toString'), "\n";
try {
    echo (string) $cl, "\n";
} catch (Error $e) {
    echo 'cast_err', "\n";
}
?>
--EXPECT--
Dom\TokenList
a b
c d
c d
0
cast_err
