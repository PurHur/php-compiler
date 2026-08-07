--TEST--
shmop_open/delete/close thin AOT (#27408)
--FILE--
<?php
$key = 0x27408;
$s = @shmop_open($key, 'c', 0644, 100);
echo $s === false ? "false\n" : "ok\n";
if ($s !== false) {
    @shmop_delete($s);
    shmop_close($s);
}
?>
--EXPECT--
ok
