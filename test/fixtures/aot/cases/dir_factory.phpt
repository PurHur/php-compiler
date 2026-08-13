--TEST--
AOT: dir() Directory factory read/rewind/close (#30757)
--FILE--
<?php
$d = dir('/tmp');
echo get_class($d), "\n";
$seen = false;
while (false !== ($e = $d->read())) {
    if ('.' === $e || '..' === $e) {
        $seen = true;
        break;
    }
}
echo $seen ? 'has_dot' : 'no_dot', "\n";
$d->rewind();
$e2 = $d->read();
echo is_string($e2) ? 'rewind_ok' : 'rewind_fail', "\n";
$d->close();
echo "closed\n";
?>
--EXPECT--
Directory
has_dot
rewind_ok
closed
