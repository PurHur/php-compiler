<?php
/**
 * Repro #20712 — ZipArchive LENGTH_UNCHECKED / ER_* / FL_OPEN_FILE_NOW / LIBZIP_VERSION.
 */
echo 'LENGTH_TO_END=', (string) ZipArchive::LENGTH_TO_END, PHP_EOL;
echo 'LENGTH_UNCHECKED=', (string) ZipArchive::LENGTH_UNCHECKED, PHP_EOL;
echo 'ER_DATA_LENGTH=', (string) ZipArchive::ER_DATA_LENGTH, PHP_EOL;
echo 'ER_TRUNCATED_ZIP=', (string) ZipArchive::ER_TRUNCATED_ZIP, PHP_EOL;
echo 'FL_OPEN_FILE_NOW=', (string) ZipArchive::FL_OPEN_FILE_NOW, PHP_EOL;
echo 'LIBZIP_VERSION=', (string) ZipArchive::LIBZIP_VERSION, PHP_EOL;
