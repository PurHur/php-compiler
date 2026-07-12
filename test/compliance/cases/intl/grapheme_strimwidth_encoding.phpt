--TEST--
intl grapheme_strimwidth() — 4th parameter is encoding not trimmarker (#17342)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo grapheme_strimwidth('日本語テスト', 0, 4, 'UTF-8'), "\n";
echo grapheme_strimwidth('hello', 0, 10), "\n";
try {
    grapheme_strimwidth('こんにちは', 0, 3, '...');
    echo "no-error\n";
} catch (\ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
日本
hello
grapheme_strimwidth(): Argument #4 ($encoding) must be a valid encoding, "..." given
