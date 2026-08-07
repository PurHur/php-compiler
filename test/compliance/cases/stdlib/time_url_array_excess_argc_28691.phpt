--TEST--
microtime/hrtime/sleep/usleep/parse_url/http_build_query/array_column/array_combine excess argc → ArgumentCountError (#28691)
--FILE--
<?php
$cases = [
    static fn () => microtime(true, 'x'),
    static fn () => hrtime(true, 'x'),
    static fn () => sleep(0, 'x'),
    static fn () => usleep(1, 'x'),
    static fn () => parse_url('http://a', -1, 'x'),
    static fn () => http_build_query([], '', '&', PHP_QUERY_RFC1738, 'x'),
    static fn () => array_column([], 'a', null, 'x'),
    static fn () => array_combine([1], [2], 'x'),
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
$p = parse_url('http://example.com/path');
echo is_array($p) ? $p['host'] : 'fail', "\n";
$a = array_combine(['a'], ['b']);
echo is_array($a) ? $a['a'] : 'fail', "\n";
?>
--EXPECT--
microtime() expects at most 1 argument, 2 given
hrtime() expects at most 1 argument, 2 given
sleep() expects exactly 1 argument, 2 given
usleep() expects exactly 1 argument, 2 given
parse_url() expects at most 2 arguments, 3 given
http_build_query() expects at most 4 arguments, 5 given
array_column() expects at most 3 arguments, 4 given
array_combine() expects exactly 2 arguments, 3 given
example.com
b
