<?php
function t(string $label, callable $fn): void
{
    try {
        $fn();
        echo $label, ": ACCEPTED\n";
    } catch (ArgumentCountError $e) {
        echo $label, ': ', $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}

$a = new ArrayObject([1]);
t('exchangeArray', fn () => $a->exchangeArray([2], 'x'));
t('getIterator', fn () => $a->getIterator('x'));
t('append', fn () => $a->append(1, 'x'));

$f = new SplFileInfo('/etc/hosts');
t('getSize', fn () => $f->getSize('x'));
t('getPathname', fn () => $f->getPathname('x'));

$tmp = sys_get_temp_dir().'/phpc_30837_'.getmypid();
@mkdir($tmp);
file_put_contents($tmp.'/a.txt', '1');
$di = new DirectoryIterator($tmp);
$hit = false;
foreach ($di as $e) {
    if ($e->isDot()) {
        continue;
    }
    t('getFilename', fn () => $e->getFilename('x'));
    t('isDot', fn () => $e->isDot('x'));
    $hit = true;
    break;
}
if (!$hit) {
    echo "getFilename: NO_ENTRY\n";
    echo "isDot: NO_ENTRY\n";
}

$ok = new ArrayObject([9]);
$ok->append(8);
echo 'ok_append: ', $ok->count(), "\n";
echo 'ok_pathname: ', (new SplFileInfo('/etc/hosts'))->getPathname(), "\n";
