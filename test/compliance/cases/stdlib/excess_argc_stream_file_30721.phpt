--TEST--
stdlib: fgets/fclose/fwrite/fputs/stream_get_contents excess argc → ArgumentCountError (#30721)
--FILE--
<?php
foreach ([
    'fgets' => static fn () => fgets(STDIN, 10, 3),
    'fclose' => static fn () => fclose(STDIN, 2),
    'fwrite' => static fn () => fwrite(STDOUT, 'x', 1, 4),
    'fputs' => static fn () => fputs(STDOUT, 'x', 1, 4),
    'stream_get_contents' => static fn () => stream_get_contents(STDIN, 1, -1, 4),
] as $name => $call) {
    try {
        $call();
        echo $name, " NO_THROW\n";
    } catch (Throwable $e) {
        echo $name, ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$tmp = tmpfile();
fwrite($tmp, "ok\n");
rewind($tmp);
$line = fgets($tmp);
echo 'ok_fgets=', (false === $line ? '0' : (trim($line) === 'ok' ? '1' : '0')), "\n";
echo 'ok_fclose=', fclose($tmp) ? '1' : '0', "\n";
--EXPECT--
fgets ArgumentCountError: fgets() expects at most 2 arguments, 3 given
fclose ArgumentCountError: fclose() expects exactly 1 argument, 2 given
fwrite ArgumentCountError: fwrite() expects at most 3 arguments, 4 given
fputs ArgumentCountError: fputs() expects at most 3 arguments, 4 given
stream_get_contents ArgumentCountError: stream_get_contents() expects at most 3 arguments, 4 given
ok_fgets=1
ok_fclose=1
