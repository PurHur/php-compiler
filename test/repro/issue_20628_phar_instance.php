<?php
/**
 * Issue #20628 — Phar instance build/add/extract round-trip (phar.readonly=0).
 */
declare(strict_types=1);

foreach (['buildFromDirectory','addFile','extractTo','setStub','offsetGet'] as $m) {
    echo $m, ' ', method_exists(Phar::class, $m) ? 'Y' : 'N', "\n";
}

$dir = sys_get_temp_dir() . '/phar20628_repro_' . getmypid() . '_' . str_replace('.', '', uniqid('', true));
@mkdir($dir, 0777, true);
$pharPath = $dir . '/app.phar';
$phar = new Phar($pharPath);
$phar->startBuffering();
$phar->addFromString('hello.txt', 'world');
$stub = Phar::createDefaultStub('hello.txt');
$phar->setStub($stub);
$phar->stopBuffering();
echo 'content=', $phar['hello.txt']->getContent(), "\n";
$out = $dir . '/out';
@mkdir($out, 0777, true);
$phar->extractTo($out);
$extracted = $out . '/hello.txt';
echo 'file=', is_file($extracted) ? (string) file_get_contents($extracted) : 'MISSING', "\n";
echo "ok\n";
