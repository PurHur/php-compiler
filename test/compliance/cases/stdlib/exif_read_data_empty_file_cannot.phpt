--TEST--
stdlib exif_read_data() empty file — Argument #1 cannot be empty (#30490)
--FILE--
<?php
try {
    exif_read_data('');
    echo "miss\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
exif_read_data(): Argument #1 ($file) cannot be empty
