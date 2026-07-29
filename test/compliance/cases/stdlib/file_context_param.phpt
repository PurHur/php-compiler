--TEST--
stdlib file() optional stream context arg (#24454, ext/standard/file.stub.php)
--FILE--
<?php
$rf = new ReflectionFunction('file');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo $rf->getNumberOfParameters(), ':', implode(',', $names), "\n";

$ctx = stream_context_create([]);
$path = sys_get_temp_dir() . '/phpc-file-ctx-' . getmypid() . '.txt';
file_put_contents($path, "a\nb\n");
$lines = file($path, 0, $ctx);
echo is_array($lines) ? count($lines) : 'bad', "\n";
$lines2 = file(filename: $path, flags: 0, context: $ctx);
echo is_array($lines2) ? count($lines2) : 'named-bad', "\n";
$lines3 = file(filename: $path, context: $ctx);
echo is_array($lines3) ? count($lines3) : 'skip-flags-bad', "\n";
@unlink($path);
--EXPECT--
3:filename,flags,context
2
2
2
