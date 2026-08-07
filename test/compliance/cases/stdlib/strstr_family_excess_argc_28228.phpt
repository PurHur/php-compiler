--TEST--
strstr/stristr/strchr excess argc → ArgumentCountError (#28228)
--FILE--
<?php
$cases = [
    static fn () => strstr('abcdef', 'c', false, true),
    static fn () => stristr('abcdef', 'c', false, true),
    static fn () => strchr('abcdef', 'c', false, true),
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
echo strstr('abcdef', 'c'), "\n";
echo strstr('abcdef', 'c', true), "\n";
echo stristr('AbCdEf', 'c'), "\n";
?>
--EXPECT--
strstr() expects at most 3 arguments, 4 given
stristr() expects at most 3 arguments, 4 given
strchr() expects at most 3 arguments, 4 given
cdef
ab
CdEf
