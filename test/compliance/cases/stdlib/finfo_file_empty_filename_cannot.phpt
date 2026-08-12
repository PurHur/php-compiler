--TEST--
stdlib finfo_file() empty filename — Argument #2 cannot be empty (#30489)
--FILE--
<?php
$finfo = finfo_open(FILEINFO_MIME_TYPE);
try {
    finfo_file($finfo, '');
    echo "miss\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
finfo_file(): Argument #2 ($filename) cannot be empty
