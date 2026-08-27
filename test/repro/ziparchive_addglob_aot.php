<?php
// ZipArchive::addGlob / addPattern — AOT must match VM (#35537 leftover of #35531 / #20387).
$dir = sys_get_temp_dir() . '/phpc_zip_35537_' . getmypid();
@mkdir($dir);
file_put_contents("$dir/a.txt", "aa");
file_put_contents("$dir/b.txt", "bb");
file_put_contents("$dir/c.dat", "cc");
$zip = sys_get_temp_dir() . '/phpc_zip_35537_' . getmypid() . '.zip';
@unlink($zip);
$z = new ZipArchive();
$z->open($zip, ZipArchive::CREATE);
$added = $z->addGlob("$dir/*.txt");
echo 'glob_n=';
echo \is_array($added) ? \count($added) : 'false';
echo "\n";
echo 'glob_base=';
if (\is_array($added)) {
    $bases = [];
    foreach ($added as $p) {
        $bases[] = \basename((string) $p);
    }
    \sort($bases);
    echo \implode(',', $bases);
}
echo "\n";
echo 'count=';
echo $z->count();
echo "\n";
$z->close();
@unlink($zip);
$z->open($zip, ZipArchive::CREATE);
$added2 = $z->addPattern('/\.txt$/', $dir);
echo 'pat_n=';
echo \is_array($added2) ? \count($added2) : 'false';
echo "\n";
echo 'pat_base=';
if (\is_array($added2)) {
    $bases2 = [];
    foreach ($added2 as $p) {
        $bases2[] = \basename((string) $p);
    }
    \sort($bases2);
    echo \implode(',', $bases2);
}
echo "\n";
echo 'count2=';
echo $z->count();
echo "\n";
$z->close();
@unlink($zip);
@unlink("$dir/a.txt");
@unlink("$dir/b.txt");
@unlink("$dir/c.dat");
@rmdir($dir);
