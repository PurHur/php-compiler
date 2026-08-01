--TEST--
stdlib RarArchive open/getEntries/extract store archive (#6237)
--ENV--
PHP_COMPILER_ENABLE_RAR=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\rar\RarExtensionPolicy::advertisesExtension()) {
    die('skip rar withheld (#6237)');
}
--FILE--
<?php
declare(strict_types=1);

echo class_exists('RarArchive') ? '1' : '0';
echo class_exists('RarEntry') ? '1' : '0';
echo class_exists('RarException') ? '1' : '0';
echo extension_loaded('rar') ? '1' : '0';
echo "\n";

$fixture = __DIR__ . '/test/fixtures/rar/tiny.rar';
if (!is_file($fixture)) {
    $fixture = dirname(__DIR__, 3).'/fixtures/rar/tiny.rar';
}
$arch = RarArchive::open($fixture);
echo $arch instanceof RarArchive ? 'arch' : 'noarch';
echo "\n";
$entries = $arch->getEntries();
echo count($entries), "\n";
$e = $entries[0];
echo $e->getName(), "\n";
echo $e->getUnpackedSize(), "\n";
echo $e->isDirectory() ? 'dir' : 'file';
echo "\n";

$out = sys_get_temp_dir().'/php-compiler-rar-'.getmypid();
@mkdir($out);
$e->extract($out);
$got = file_get_contents($out.'/hello.txt');
echo $got === "hello rar\n" ? "ok\n" : "bad:$got\n";

try {
    RarArchive::open('/no/such/archive-'.getmypid().'.rar');
    echo "unexpected_ok\n";
} catch (RarException $ex) {
    echo "ex\n";
}
$arch->close();
?>
--EXPECT--
1111
arch
1
hello.txt
10
file
ok
ex
