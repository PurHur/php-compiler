<?php
declare(strict_types=1);

// #26181 — finfo::__construct Reflection flags/magic_database (php-src fileinfo.stub.php)
$r = new ReflectionMethod('finfo', '__construct');
foreach ($r->getParameters() as $p) {
    echo '$', $p->getName(), ':', $p->hasType() ? (string) $p->getType() : 'NONE',
        ' opt=', $p->isOptional() ? '1' : '0', "\n";
}
try {
    $f = new finfo(flags: FILEINFO_MIME_TYPE);
    echo 'flags_named=', $f->buffer('<?php'), "\n";
} catch (Throwable $e) {
    echo 'flags:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $f = new finfo(options: FILEINFO_MIME_TYPE);
    echo 'options_named=', $f->buffer('<?php'), "\n";
} catch (Throwable $e) {
    echo 'options:', get_class($e), ':', $e->getMessage(), "\n";
}
