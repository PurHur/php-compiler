<?php
/**
 * fgets/fclose/fwrite/fputs/stream_get_contents excess argc → ArgumentCountError (#30721).
 * php-src: ext/standard/file.c
 */
try {
    fgets(STDIN, 10, 3);
    echo "fgets:OK\n";
} catch (ArgumentCountError $e) {
    echo 'fgets:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'fgets:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    fclose(STDIN, 2);
    echo "fclose:OK\n";
} catch (ArgumentCountError $e) {
    echo 'fclose:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'fclose:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    fwrite(STDOUT, 'x', 1, 4);
    echo "fwrite:OK\n";
} catch (ArgumentCountError $e) {
    echo 'fwrite:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'fwrite:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    fputs(STDOUT, 'x', 1, 4);
    echo "fputs:OK\n";
} catch (ArgumentCountError $e) {
    echo 'fputs:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'fputs:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    stream_get_contents(STDIN, 1, -1, 4);
    echo "stream_get_contents:OK\n";
} catch (ArgumentCountError $e) {
    echo 'stream_get_contents:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'stream_get_contents:', get_class($e), ':', $e->getMessage(), "\n";
}

$tmp = tmpfile();
fwrite($tmp, "ok\n");
rewind($tmp);
$line = fgets($tmp);
echo 'ok_fgets:', (false === $line ? 'false' : trim($line)), "\n";
fclose($tmp);
echo "ok_fclose:1\n";
