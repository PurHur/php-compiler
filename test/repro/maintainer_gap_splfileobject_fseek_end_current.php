<?php
declare(strict_types=1);

$fo = new SplFileObject('php://memory', 'w+');
$fo->fwrite("line0\nline1\nline2\n");
$fo->rewind();
$fo->seek(1);
$fo->fseek(0, SEEK_END);
$key = $fo->key();
$valid = $fo->valid();
$current = $fo->current();
$pass = 1 === $key
    && true === $valid
    && '' === $current;
echo $pass ? "ok\n" : "fail key={$key} valid=".var_export($valid, true).' current='.var_export($current, true)."\n";
exit($pass ? 0 : 1);
