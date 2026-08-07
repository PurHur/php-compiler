--TEST--
String search/span excess argc → ArgumentCountError (#28311)
--FILE--
<?php
$cases = [
    static fn () => strcspn('abc', 'a', 0, 1, 'x'),
    static fn () => strspn('abc', 'a', 0, 1, 'x'),
    static fn () => substr_count('aaa', 'a', 0, 1, 'x'),
    static fn () => stripos('abc', 'a', 0, 'x'),
    static fn () => strripos('abc', 'a', 0, 'x'),
    static fn () => strrpos('abc', 'a', 0, 'x'),
];
foreach ($cases as $fn) {
    try {
        $fn();
        echo "NO_THROW\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
echo strcspn('abc', 'b'), "\n";
echo stripos('AbC', 'b'), "\n";
?>
--EXPECT--
strcspn() expects at most 4 arguments, 5 given
strspn() expects at most 4 arguments, 5 given
substr_count() expects at most 4 arguments, 5 given
stripos() expects at most 3 arguments, 4 given
strripos() expects at most 3 arguments, 4 given
strrpos() expects at most 3 arguments, 4 given
1
1
