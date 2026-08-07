<?php
/**
 * #28286 — basename/dirname/pathinfo excess argc → ArgumentCountError (Zend), not LogicException.
 *
 * php-src: ext/standard/file.stub.php
 */
$cases = [
    'basename_ok' => static function () {
        return basename('/a/b.txt', '.txt');
    },
    'dirname_ok' => static function () {
        return dirname('/a/b.txt', 1);
    },
    'pathinfo_ok' => static function () {
        return pathinfo('/a/b.txt', PATHINFO_FILENAME);
    },
    'basename' => static function () {
        basename('/a/b', '.b', true);
    },
    'dirname' => static function () {
        dirname('/a/b', 1, true);
    },
    'pathinfo' => static function () {
        pathinfo('/a/b', PATHINFO_FILENAME, true);
    },
    'basename0' => static function () {
        basename();
    },
];
foreach ($cases as $name => $fn) {
    try {
        $r = $fn();
        echo $name, ':OK:', (string) $r, "\n";
    } catch (Throwable $e) {
        echo $name, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
