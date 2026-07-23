--TEST--
stdlib PDOException::$errorInfo after failed exec (#22455, ext/pdo/pdo.stub.php)
--FILE--
<?php
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
try {
    $pdo->exec('select * from no_such_table');
    echo "no_throw\n";
} catch (PDOException $e) {
    echo 'has_errorInfo_prop=', (int) (new ReflectionObject($e))->hasProperty('errorInfo'), "\n";
    echo 'errorInfo_isset=', (int) isset($e->errorInfo), "\n";
    $ei = $e->errorInfo;
    echo 'is_array=', (int) is_array($ei), "\n";
    echo 'sqlstate=', is_array($ei) ? (string) $ei[0] : '', "\n";
    echo 'has_msg=', (is_array($ei) && isset($ei[2]) && is_string($ei[2]) && '' !== $ei[2]) ? '1' : '0', "\n";
}
?>
--EXPECT--
has_errorInfo_prop=1
errorInfo_isset=1
is_array=1
sqlstate=HY000
has_msg=1
