--TEST--
stdlib PDO sqlite::memory: connect/prepare/query (#3367, ext/pdo)
--FILE--
<?php
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
$st = $pdo->prepare('INSERT INTO t (v) VALUES (?)');
$st->execute(['hello']);
foreach ($pdo->query('SELECT v FROM t') as $row) {
    echo $row['v'], "\n";
}
echo 'errmode=', $pdo->getAttribute(PDO::ATTR_ERRMODE), "\n";
$drivers = PDO::getAvailableDrivers();
echo 'sqlite_driver=', in_array('sqlite', $drivers, true) ? '1' : '0', "\n";
?>
--EXPECT--
hello
errmode=2
sqlite_driver=1
