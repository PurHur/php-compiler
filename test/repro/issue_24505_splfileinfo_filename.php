<?php

/**
 * #24505 — SplFileInfo::__construct Reflection/named param is filename (php-src spl_directory.stub.php).
 */
$r = new ReflectionMethod('SplFileInfo', '__construct');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'params=', implode(',', $names), "\n";

try {
    $o = new SplFileInfo(filename: __FILE__);
    echo 'named_filename=', $o->getFilename() !== '' ? 'ok' : 'empty', "\n";
} catch (Throwable $e) {
    echo 'named_filename=', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $o = new SplFileInfo(file_name: __FILE__);
    echo 'named_file_name=ok', "\n";
} catch (Throwable $e) {
    echo 'named_file_name=', get_class($e), "\n";
}
