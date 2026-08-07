--TEST--
AOT: PDO::getAvailableDrivers + missing sqlite DSN throws (#27619)
--FILE--
<?php
$drivers = PDO::getAvailableDrivers();
echo 'is_array=', is_array($drivers) ? '1' : '0', "\n";
echo 'count=', is_array($drivers) ? (string) count($drivers) : 'n/a', "\n";
echo 'has_sqlite=', is_array($drivers) && in_array('sqlite', $drivers, true) ? '1' : '0', "\n";
try {
    new PDO('sqlite::memory:');
    echo "connected\n";
} catch (Throwable $e) {
    echo 'PDOException: ', $e->getMessage(), "\n";
}
?>
--EXPECT--
is_array=1
count=0
has_sqlite=0
PDOException: could not find driver
