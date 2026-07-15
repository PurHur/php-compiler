--TEST--
stdlib PharData tar addFromString/extractTo (#6490)
--FILE--
<?php
declare(strict_types=1);

echo class_exists('PharData', false) ? "class_ok\n" : "class_bad\n";
echo is_subclass_of('PharData', 'Phar') ? "extends_ok\n" : "extends_bad\n";

$base = sys_get_temp_dir() . '/phardata_c_' . getmypid() . '_' . mt_rand();
mkdir($base);
$tar = $base . '/demo.tar';

$p = new PharData($tar);
$p->addFromString('a.txt', 'hello');
echo $p['a.txt']->getContent() === 'hello' ? "member_ok\n" : "member_bad\n";

$out = $base . '/out';
mkdir($out);
echo $p->extractTo($out) ? "extract_ok\n" : "extract_bad\n";
$extracted = $out . '/a.txt';
echo is_file($extracted) ? "file_ok\n" : "file_bad\n";
echo file_get_contents($extracted) === 'hello' ? "content_ok\n" : "content_bad\n";
--EXPECT--
class_ok
extends_ok
member_ok
extract_ok
file_ok
content_ok
