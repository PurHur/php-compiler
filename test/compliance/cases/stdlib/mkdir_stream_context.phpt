--TEST--
stdlib mkdir() optional stream context fourth argument (#23453, ext/standard/filestat.c)
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/mkdir_fixture';
$dir = $base . '/ctx_' . getmypid();
@rmdir($dir);
$ctx = stream_context_create([]);
var_dump(mkdir($dir, 0777, false, $ctx));
var_dump(is_dir($dir));
@rmdir($dir);
$r = new ReflectionFunction('mkdir');
echo implode(',', array_map(fn($p) => $p->getName(), $r->getParameters())), "\n";
$dir2 = $base . '/ctx_named_' . getmypid();
@rmdir($dir2);
$ok = mkdir(directory: $dir2, permissions: 0777, recursive: false, context: $ctx);
var_dump($ok && is_dir($dir2));
@rmdir($dir2);
?>
--EXPECT--
bool(true)
bool(true)
directory,permissions,recursive,context
bool(true)
