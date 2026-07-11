--TEST--
stdlib stream_set_blocking() — null $mode rejected under declare(strict_types=1) (#16524, ext/standard/streams.c)
--FILE--
<?php
declare(strict_types=1);
$fp = fopen('php://memory', 'r+');
try {
    stream_set_blocking($fp, null);
    echo "fail\n";
} catch (TypeError $e) {
    echo "ok\n";
}
?>
--EXPECT--
ok
