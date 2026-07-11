--TEST--
stdlib stream_select() accepts tmpfile() resource array (#14014)
--FILE--
<?php
declare(strict_types=1);
$f = tmpfile();
if (false === $f) {
    fwrite(STDERR, "tmpfile() failed\n");
    exit(1);
}
$read = [$f];
$write = null;
$except = null;
$n = stream_select($read, $write, $except, 0);
if (!is_int($n)) {
    fwrite(STDERR, 'expected int from stream_select(), got ');
    var_export($n);
    fwrite(STDERR, "\n");
    exit(1);
}
echo "ok\n";
?>
--EXPECT--
ok
