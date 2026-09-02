<?php
// intl/zip/sqlite3 thin-AOT owned by Module::jitInit (#36204).
echo Normalizer::FORM_C, ',', NumberFormatter::DECIMAL, "\n";
$z = new ZipArchive();
echo isset($z->status) ? 'zip' : 'no', "\n";
if (class_exists('SQLite3')) {
    $db = new SQLite3(':memory:');
    echo $db->querySingle('SELECT 1'), "\n";
    $db->close();
} else {
    echo "skip-sqlite3\n";
}
