--TEST--
ext/phar Phar extends RecursiveDirectoryIterator (#22293, php-src phar_object.c)
--INI--
phar.readonly=0
--FILE--
<?php
declare(strict_types=1);
echo 'parent=', var_export(get_parent_class(Phar::class), true), "\n";
echo 'subclass=', is_subclass_of(Phar::class, SplFileInfo::class) ? 'Y' : 'N', "\n";
echo 'getFilename=', method_exists(Phar::class, 'getFilename') ? 'Y' : 'N', "\n";
echo 'key=', method_exists(Phar::class, 'key') ? 'Y' : 'N', "\n";
$tmp = sys_get_temp_dir() . '/phar_rdi_' . getmypid() . '.phar';
@unlink($tmp);
$p = new Phar($tmp);
$p->startBuffering();
$p->addFromString('a.txt', 'hi');
$p->setDefaultStub();
$p->stopBuffering();
unset($p);
$p = new Phar($tmp);
echo 'fn=', var_export($p->getFilename(), true), "\n";
echo 'valid=', $p->valid() ? 'Y' : 'N', "\n";
if ($p->valid()) {
    $cur = $p->current();
    echo 'cur=', get_class($cur), "\n";
    echo 'cur_fn=', $cur->getFilename(), "\n";
}
@unlink($tmp);
?>
--EXPECT--
parent='RecursiveDirectoryIterator'
subclass=Y
getFilename=Y
key=Y
fn='a.txt'
valid=Y
cur=PharFileInfo
cur_fn=a.txt
