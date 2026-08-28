<?php
/**
 * #34624 — AOT RecursiveDirectoryIterator foreach (php-src spl_directory.c).
 *
 * Fixture: test/fixtures/aot/cases/directoryiterator_27289_fixture/ (a.txt only).
 * RII+RDI must not SIGSEGV; with SKIP_DOTS LEAVES_ONLY flatten skips `.`/`..` (#34624).
 */
$dir = __DIR__.'/../fixtures/aot/cases/directoryiterator_27289_fixture';

$rdi = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
$names = [];
foreach ($rdi as $f) {
    $names[] = $f->getFilename();
}
sort($names);
echo 'rdi:', implode(',', $names), "\n";

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
);
$leaf = [];
foreach ($rii as $f) {
    $leaf[] = is_object($f) ? $f->getFilename() : (string) $f;
}
sort($leaf);
echo 'rii:', implode(',', $leaf), "\n";
echo 'rii_ok:', (implode(',', $leaf) === 'a.txt' ? '1' : '0'), "\n";
