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
$missing = @dir('/tmp/phpc-dir-missing-'.getmypid());
var_export($missing);
echo "\n";
