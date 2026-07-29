<?php

/**
 * #24503 — DirectoryIterator*::__construct first param is directory (php-src spl_directory.stub.php).
 */
$dir = sys_get_temp_dir();
foreach (['DirectoryIterator', 'FilesystemIterator', 'RecursiveDirectoryIterator'] as $c) {
    $r = new ReflectionMethod($c, '__construct');
    $names = [];
    foreach ($r->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $c, ' params=', implode(',', $names), "\n";
    try {
        $o = new $c(directory: $dir);
        echo $c, ' named_directory=ok', "\n";
        unset($o);
    } catch (Throwable $e) {
        echo $c, ' named_directory=', get_class($e), "\n";
    }
    try {
        $o = new $c(path: $dir);
        echo $c, ' named_path=ok', "\n";
        unset($o);
    } catch (Throwable $e) {
        echo $c, ' named_path=', get_class($e), "\n";
    }
}
