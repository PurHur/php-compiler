--TEST--
ZipArchive unchange, replaceFile/addGlob/addPattern (#20387)
--ENV--
PHP_COMPILER_ENABLE_ZIP=1
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\ext\zip\ZipExtensionPolicy::advertisesExtension()) {
    die('skip zip withheld (#18137/#25010)');
}
?>
--FILE--
<?php
$need = [
    'unchangeArchive',
    'unchangeAll',
    'unchangeName',
    'unchangeIndex',
    'replaceFile',
    'addGlob',
    'addPattern',
];
$bits = '';
foreach ($need as $m) {
    $bits .= method_exists('ZipArchive', $m) ? '1' : '0';
}
echo "methods=$bits\n";

$base = sys_get_temp_dir() . '/phpc_zip_unch_' . getmypid();
@mkdir($base);
$path = $base . '/a.zip';
@unlink($path);

$zip = new ZipArchive();
$flags = ZipArchive::CREATE | ZipArchive::OVERWRITE;
$zip->open($path, $flags);
$zip->addFromString('a.txt', 'hello');
$zip->addFromString('b.txt', 'world');
$zip->setArchiveComment('arch');
$zip->close();

$zip = new ZipArchive();
$zip->open($path);
$before = $zip->statName('a.txt');
echo 'before=', $before['name'], ':', $before['size'], "\n";
$zip->addFromString('a.txt', 'CHANGED');
$mid = $zip->statName('a.txt');
echo 'mid=', $mid['name'], ':', $mid['size'], "\n";
echo 'unchName=', var_export($zip->unchangeName('a.txt'), true), "\n";
$after = $zip->statName('a.txt');
echo 'after=', $after['name'], ':', $after['size'], ' data=', $zip->getFromName('a.txt'), "\n";

$zip->setArchiveComment('mutated');
echo 'archMut=', var_export($zip->getArchiveComment(), true), "\n";
echo 'unchArch=', var_export($zip->unchangeArchive(), true), "\n";
echo 'archRest=', var_export($zip->getArchiveComment(), true), "\n";

$zip->addFromString('a.txt', 'AGAIN');
$zip->addFromString('c.txt', 'new');
echo 'unchAll=', var_export($zip->unchangeAll(), true), ' num=', $zip->numFiles, "\n";
echo 'afterAll=', $zip->getFromName('a.txt'), ' hasC=', var_export($zip->locateName('c.txt'), true), "\n";

$repl = $base . '/repl.txt';
file_put_contents($repl, 'REPLACED');
echo 'repl=', var_export($zip->replaceFile($repl, 1), true), "\n";
echo 'replData=', $zip->getFromIndex(1), "\n";

$one = $base . '/one.txt';
$two = $base . '/two.txt';
$bin = $base . '/skip.bin';
file_put_contents($one, '1');
file_put_contents($two, '2');
file_put_contents($bin, 'x');
$added = $zip->addGlob($base . '/one.txt', 0, ['remove_all_path' => true, 'add_path' => 'g/']);
$addedB = $zip->addGlob($base . '/two.txt', 0, ['remove_all_path' => true, 'add_path' => 'g/']);
echo 'glob=', (is_array($added) && is_array($addedB)) ? (count($added) + count($addedB)) : 'fail', ' one=', var_export($zip->locateName('g/one.txt') !== false, true), "\n";

$added2 = $zip->addPattern('/\\.bin$/', $base, ['remove_all_path' => true, 'add_path' => 'p/']);
echo 'pat=', is_array($added2) ? count($added2) : 'fail', ' bin=', var_export($zip->locateName('p/skip.bin') !== false, true), "\n";

$zip->close();
@unlink($path);
@unlink($repl);
@unlink($one);
@unlink($two);
@unlink($bin);
@rmdir($base);
?>
--EXPECT--
methods=1111111
before=a.txt:5
mid=a.txt:7
unchName=true
after=a.txt:5 data=hello
archMut='mutated'
unchArch=true
archRest='arch'
unchAll=true num=2
afterAll=hello hasC=false
repl=true
replData=REPLACED
glob=2 one=true
pat=1 bin=true
