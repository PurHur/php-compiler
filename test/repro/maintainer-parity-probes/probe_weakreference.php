<?php
$o = new stdClass();
$wr = WeakReference::create($o);
if ($wr->get() !== $o) {
    fwrite(STDERR, "live referent mismatch\n");
    exit(1);
}
$o = null;
if (null !== $wr->get()) {
    fwrite(STDERR, "collected referent must be null\n");
    exit(1);
}
echo "ok\n";
