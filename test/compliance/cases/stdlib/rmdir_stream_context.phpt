--TEST--
stdlib rmdir() optional stream context + directory named param (#23454, ext/standard/filestat.c)
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/rmdir_fixture';
@mkdir($base, 0777, true);
$dir = $base . '/ctx_' . getmypid();
@mkdir($dir);
$ctx = stream_context_create([]);
var_dump(rmdir($dir, $ctx));
$r = new ReflectionFunction('rmdir');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
$dir2 = $base . '/named_' . getmypid();
@mkdir($dir2);
var_dump(rmdir(directory: $dir2));
$dir3 = $base . '/named_ctx_' . getmypid();
@mkdir($dir3);
var_dump(rmdir(directory: $dir3, context: $ctx));
--EXPECT--
bool(true)
directory,context
bool(true)
bool(true)
