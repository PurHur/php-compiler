--TEST--
stdlib gz*()/zlib_*() — TypeError/ValueError for invalid int operands (#4497, ext/zlib/zlib.c)
--FILE--
<?php
$cases = [
    fn () => gzcompress('hi', 'nope'),
    fn () => gzcompress('hi', 999),
    fn () => gzdeflate('hi', 'nope'),
    fn () => gzuncompress('hi', 'nope'),
    fn () => zlib_encode('hi', 'nope'),
];
foreach ($cases as $fn) {
    try {
        $fn();
        echo "uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    } catch (ValueError $e) {
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
gzcompress(): Argument #2 ($level) must be of type int, string given
gzcompress(): Argument #2 ($level) must be between -1 and 9
gzdeflate(): Argument #2 ($level) must be of type int, string given
gzuncompress(): Argument #2 ($max_length) must be of type int, string given
zlib_encode(): Argument #2 ($encoding) must be of type int, string given
