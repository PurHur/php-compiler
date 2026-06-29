--TEST--
stdlib data:// wrapper streams — fopen/file_get_contents/copy (#10263)
--FILE--
<?php
declare(strict_types=1);

$uri = 'data://text/plain,hello';

echo (fopen($uri, 'r') !== false) ? 'fopen_ok' : 'fopen_fail';
echo "\n";
echo file_get_contents($uri);
echo "\n";
echo copy('data://text/plain,x', 'php://memory') ? 'copy_ok' : 'copy_fail';
--EXPECT--
fopen_ok
hello
copy_ok
