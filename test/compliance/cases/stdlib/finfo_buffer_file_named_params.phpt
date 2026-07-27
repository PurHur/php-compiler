--TEST--
stdlib finfo::buffer/file Zend stub Reflection + named params (#23410, ext/fileinfo/fileinfo.stub.php)
--FILE--
<?php
declare(strict_types=1);

$rb = new ReflectionMethod('finfo', 'buffer');
$bn = [];
foreach ($rb->getParameters() as $p) {
    $bn[] = $p->getName();
}
echo 'buffer=', implode(',', $bn), "\n";

$rf = new ReflectionMethod('finfo', 'file');
$fn = [];
foreach ($rf->getParameters() as $p) {
    $fn[] = $p->getName();
}
echo 'file=', implode(',', $fn), "\n";

$fi = new finfo(FILEINFO_MIME_TYPE);
echo 'mime=', $fi->buffer(string: 'hello'), "\n";

$path = sys_get_temp_dir().'/phpc_finfo_23410_compliance.txt';
file_put_contents($path, "<?php\n");
try {
    echo 'file_mime=', $fi->file(filename: $path), "\n";
} finally {
    @unlink($path);
}
--EXPECT--
buffer=string,flags,context
file=filename,flags,context
mime=text/plain
file_mime=text/x-php
