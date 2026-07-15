<?php

declare(strict_types=1);

/**
 * #3366 — finfo_open/finfo_file/finfo_buffer MIME sniff (ext/fileinfo).
 */

$tmp = tempnam(sys_get_temp_dir(), 'finfo');
file_put_contents($tmp, "<?php echo 1;\n");

$f = finfo_open(FILEINFO_MIME_TYPE);
if (false === $f || !($f instanceof finfo)) {
    fwrite(STDERR, "finfo_open_failed\n");
    exit(1);
}

echo finfo_file($f, $tmp), "\n";
echo finfo_buffer($f, '<?php echo 1;'), "\n";

$bad = @finfo_file($f, $tmp.'-missing');
echo false === $bad ? "bad_false\n" : "bad_not_false\n";

finfo_set_flags($f, FILEINFO_MIME);
echo finfo_buffer($f, 'hello'), "\n";

finfo_close($f);

$obj = new finfo(FILEINFO_MIME_TYPE);
echo $obj->file($tmp), "\n";
echo $obj->buffer('<?php'), "\n";

@unlink($tmp);
echo "ok\n";
