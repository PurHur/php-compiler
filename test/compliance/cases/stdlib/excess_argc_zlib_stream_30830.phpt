--TEST--
stdlib: zlib stream helpers ArgumentCountError wording (#30830)
--FILE--
<?php
$src = sys_get_temp_dir().'/phpc_30830_phpt_src_'.getmypid().'.txt';
file_put_contents($src, "hello zlib stream argc\n");
$zp = gzopen($src, 'r');
$tmp = sys_get_temp_dir().'/phpc_30830_phpt_'.getmypid().'.gz';
$zw = gzopen($tmp, 'w');
if ($zp === false || $zw === false) {
    echo "OPEN_FAIL\n";
    exit(1);
}
$cases = [
    'gzclose' => static fn () => gzclose($zp, 1),
    'gzeof' => static fn () => gzeof($zp, 1),
    'gzgetc' => static fn () => gzgetc($zp, 1),
    'gzgets' => static fn () => gzgets($zp, 1024, 1),
    'gzpassthru' => static fn () => gzpassthru($zp, 1),
    'gzrewind' => static fn () => gzrewind($zp, 1),
    'gzseek' => static fn () => gzseek($zp, 0, SEEK_SET, 1),
    'gztell' => static fn () => gztell($zp, 1),
    'gzread' => static fn () => gzread($zp, 10, 1),
    'gzwrite' => static fn () => gzwrite($zw, 'a', 1, 1),
    'gzputs' => static fn () => gzputs($zw, 'a', 1, 1),
];
foreach ($cases as $name => $call) {
    try {
        $call();
        echo $name, " NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$chunk = gzread($zp, 4);
echo 'ok=', (is_string($chunk) && strlen($chunk) <= 4) ? '1' : '0', "\n";
@gzclose($zp);
@gzclose($zw);
@unlink($tmp);
@unlink($src);
--EXPECT--
gzclose ArgumentCountError: gzclose() expects exactly 1 argument, 2 given
gzeof ArgumentCountError: gzeof() expects exactly 1 argument, 2 given
gzgetc ArgumentCountError: gzgetc() expects exactly 1 argument, 2 given
gzgets ArgumentCountError: gzgets() expects at most 2 arguments, 3 given
gzpassthru ArgumentCountError: gzpassthru() expects exactly 1 argument, 2 given
gzrewind ArgumentCountError: gzrewind() expects exactly 1 argument, 2 given
gzseek ArgumentCountError: gzseek() expects at most 3 arguments, 4 given
gztell ArgumentCountError: gztell() expects exactly 1 argument, 2 given
gzread ArgumentCountError: gzread() expects exactly 2 arguments, 3 given
gzwrite ArgumentCountError: gzwrite() expects at most 3 arguments, 4 given
gzputs ArgumentCountError: gzputs() expects at most 3 arguments, 4 given
ok=1
