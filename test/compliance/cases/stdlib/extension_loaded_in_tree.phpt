--TEST--
stdlib extension_loaded/get_loaded_extensions in-tree modules (#7190, #6372)
--FILE--
<?php
declare(strict_types=1);

echo 'hash: fn=', (int) function_exists('hash'), ' loaded=', (int) extension_loaded('hash'), "\n";
echo 'json: fn=', (int) function_exists('json_encode'), ' loaded=', (int) extension_loaded('json'), "\n";
echo 'date: fn=', (int) function_exists('date'), ' loaded=', (int) extension_loaded('date'), "\n";
echo 'random: loaded=', (int) extension_loaded('random'), "\n";
echo 'zip: loaded=', (int) extension_loaded('zip'), ' class=', (int) class_exists('ZipArchive'), "\n";
echo 'spl: loaded=', (int) extension_loaded('spl'), ' class=', (int) class_exists('ArrayObject'), "\n";
$exts = get_loaded_extensions();
echo 'hash_in_list=', (int) in_array('hash', $exts, true), "\n";
echo 'json_in_list=', (int) in_array('json', $exts, true), "\n";
echo 'standard_in_list=', (int) in_array('standard', $exts, true), "\n";
echo 'types_in_list=', (int) in_array('types', $exts, true), "\n";
echo 'zip_in_list=', (int) in_array('zip', $exts, true), "\n";
echo 'spl_in_list=', (int) in_array('spl', $exts, true), "\n";
echo 'nonexistent=', (int) extension_loaded('nonexistent_xyz'), "\n";
--EXPECT--
hash: fn=1 loaded=1
json: fn=1 loaded=1
date: fn=1 loaded=1
random: loaded=1
zip: loaded=1 class=1
spl: loaded=1 class=1
hash_in_list=1
json_in_list=1
standard_in_list=1
types_in_list=1
zip_in_list=1
spl_in_list=1
nonexistent=0
