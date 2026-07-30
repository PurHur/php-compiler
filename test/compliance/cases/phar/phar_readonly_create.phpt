--TEST--
new Phar under phar.readonly=1 throws; existing open + PharData create OK (#25168)
--FILE--
<?php
declare(strict_types=1);
$dir = sys_get_temp_dir() . '/phar25168_' . getmypid() . '_' . str_replace('.', '', uniqid('', true));
@mkdir($dir, 0777, true);
$missing = $dir . '/new.phar';
@unlink($missing);
try {
    $p = new Phar($missing);
    echo "create_ok=", get_class($p), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo (str_contains($e->getMessage(), 'phar.readonly') && str_contains($e->getMessage(), 'creating archive')) ? "msg_ok\n" : ("msg=".$e->getMessage()."\n");
}
$fixture = __DIR__ . '/test/fixtures/phar/zend_classic_hi.phar';
if (!is_file($fixture)) {
    $fixture = dirname(__DIR__, 3) . '/fixtures/phar/zend_classic_hi.phar';
}
$open = new Phar($fixture);
echo "open_existing=", isset($open['a.txt']) ? '1' : '0', "\n";
$tar = $dir . '/new.tar';
@unlink($tar);
$d = new PharData($tar);
echo "phardata=", get_class($d), "\n";
?>
--EXPECT--
UnexpectedValueException
msg_ok
open_existing=1
phardata=PharData
