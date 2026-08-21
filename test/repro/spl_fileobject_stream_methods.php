<?php
declare(strict_types=1);
// #33318 — AOT SplFileObject fgets/fwrite must use a live stream handle (Zend zim_SplFileObject_*).
$tmp = tempnam(sys_get_temp_dir(), 'splfo');
if (false === $tmp) {
    exit(1);
}
file_put_contents($tmp, "line1\nline2\n");
$f = new SplFileObject($tmp, 'r+');
$line = $f->fgets();
echo $line === "line1\n" ? "fgets-ok\n" : "fgets-bad:".var_export($line, true)."\n";
$written = $f->fwrite('tail');
echo is_int($written) && $written > 0 ? "fwrite-ok\n" : "fwrite-bad\n";
@unlink($tmp);
