--TEST--
AOT: ZipArchive LENGTH_UNCHECKED / ER_* / FL_OPEN_FILE_NOW / LIBZIP_VERSION (#20712)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo (int) ZipArchive::LENGTH_UNCHECKED, "\n";
echo (int) ZipArchive::ER_DATA_LENGTH, "\n";
echo (int) ZipArchive::ER_TRUNCATED_ZIP, "\n";
echo (int) ZipArchive::FL_OPEN_FILE_NOW, "\n";
echo ZipArchive::LIBZIP_VERSION, "\n";
?>
--EXPECT--
-2
33
35
1073741824
1.11.3
