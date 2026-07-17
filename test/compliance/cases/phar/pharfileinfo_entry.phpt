--TEST--
stdlib PharFileInfo class + entry metadata (#19892, ext/phar/phar_object.c)
--FILE--
<?php
echo 'class=', class_exists('PharFileInfo') ? '1' : '0', "\n";
echo 'parent=', var_export(get_parent_class('PharFileInfo'), true), "\n";
$tmp = sys_get_temp_dir() . '/pharfileinfo_' . getmypid() . '.tar';
@unlink($tmp);
$p = new PharData($tmp);
$p->addFromString('dir/a.txt', 'hello');
$info = $p['dir/a.txt'];
echo 'entry_class=', get_class($info), "\n";
echo 'fn=', $info->getFilename(), "\n";
echo 'crcChecked=', $info->isCRCChecked() ? '1' : '0', "\n";
echo 'content=', $info->getContent(), "\n";
echo 'csize=', $info->getCompressedSize(), "\n";
echo 'isComp=', $info->isCompressed() ? '1' : '0', "\n";
@unlink($tmp);
?>
--EXPECT--
class=1
parent='SplFileInfo'
entry_class=PharFileInfo
fn=a.txt
crcChecked=1
content=hello
csize=5
isComp=0
