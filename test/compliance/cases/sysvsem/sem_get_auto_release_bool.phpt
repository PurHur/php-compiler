--TEST--
sysvsem sem_get() auto_release bool + int coerce (#19515)
--SKIPIF--
<?php if (!function_exists('sem_get')) { print 'skip sysvsem unavailable'; } ?>
--FILE--
<?php
$key = 0x19515;
$a = @sem_get($key, 1, 0666, 1);
echo is_object($a) ? get_class($a) : 'int-fail', "\n";
if (is_object($a)) {
    @sem_remove($a);
}
$b = @sem_get($key + 1, 1, 0666, true);
echo is_object($b) ? get_class($b) : 'bool-fail', "\n";
if (is_object($b)) {
    @sem_remove($b);
}
$rf = new ReflectionFunction('sem_get');
$p = $rf->getParameters();
echo $p[2]->getName(), "\n";
echo $p[3]->getName(), "\n";
echo (string) $p[3]->getType(), "\n";
?>
--EXPECT--
SysvSemaphore
SysvSemaphore
permissions
auto_release
bool
