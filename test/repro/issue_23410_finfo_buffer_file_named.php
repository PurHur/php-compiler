<?php
/**
 * Repro #23410 — finfo::buffer/file Reflection + Zend stub named params.
 * php-src: ext/fileinfo/fileinfo.stub.php
 */
$rb = new ReflectionMethod('finfo', 'buffer');
echo 'n_buffer=', $rb->getNumberOfParameters(), "\n";
foreach ($rb->getParameters() as $p) {
    echo 'b=', $p->getName(), "\n";
}
$rf = new ReflectionMethod('finfo', 'file');
echo 'n_file=', $rf->getNumberOfParameters(), "\n";
foreach ($rf->getParameters() as $p) {
    echo 'f=', $p->getName(), "\n";
}

$fi = new finfo(FILEINFO_MIME_TYPE);
try {
    echo 'mime=', $fi->buffer(string: 'hello'), "\n";
} catch (Throwable $e) {
    echo 'err=', $e->getMessage(), "\n";
}
$path = sys_get_temp_dir().'/phpc_finfo_23410.txt';
file_put_contents($path, "<?php\n");
try {
    echo 'file_mime=', $fi->file(filename: $path), "\n";
} catch (Throwable $e) {
    echo 'file_err=', $e->getMessage(), "\n";
} finally {
    @unlink($path);
}
