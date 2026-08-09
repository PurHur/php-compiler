--TEST--
stdlib APCUIterator + APC_ITER_*/APC_LIST_* when apcu advertised (#27877)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('apcu') ? "ext=Y\n" : "ext=N\n";
echo class_exists('APCUIterator', false) ? "APCUIterator=Y\n" : "APCUIterator=N\n";
foreach (['APC_ITER_ALL', 'APC_ITER_KEY', 'APC_ITER_VALUE', 'APC_LIST_ACTIVE', 'APC_LIST_DELETED'] as $c) {
    echo $c, '=', defined($c) ? 'Y' : 'N', "\n";
}
echo 'APC_ITER_KEY=', (string) (defined('APC_ITER_KEY') ? APC_ITER_KEY : -1), "\n";
echo 'APC_LIST_ACTIVE=', (string) (defined('APC_LIST_ACTIVE') ? APC_LIST_ACTIVE : -1), "\n";

apcu_clear_cache();
apcu_store('k1', 1);
apcu_store('k2', 2);
apcu_store('other', 3);

$keys = [];
foreach (new APCUIterator(null, APC_ITER_KEY) as $k => $row) {
    $keys[] = $k;
    echo 'row_key=', isset($row['key']) ? $row['key'] : '?', "\n";
}
sort($keys);
echo 'keys=', implode(',', $keys), "\n";
echo 'n=', (string) count($keys), "\n";

$it = new APCUIterator('/^k/', APC_ITER_ALL);
echo 'total=', (string) $it->getTotalCount(), "\n";
$matched = [];
foreach ($it as $k => $row) {
    $matched[] = $k;
    echo 'val=', isset($row['value']) ? (string) $row['value'] : '?', "\n";
}
sort($matched);
echo 'matched=', implode(',', $matched), "\n";
?>
--EXPECT--
ext=Y
APCUIterator=Y
APC_ITER_ALL=Y
APC_ITER_KEY=Y
APC_ITER_VALUE=Y
APC_LIST_ACTIVE=Y
APC_LIST_DELETED=Y
APC_ITER_KEY=2
APC_LIST_ACTIVE=1
row_key=k1
row_key=k2
row_key=other
keys=k1,k2,other
n=3
total=2
val=1
val=2
matched=k1,k2
