<?php
// AOT: SplObjectStorage unserialize restores __spl_ht (#33876).
$_ = new SplObjectStorage();
unset($_);
$wire = 'O:16:"SplObjectStorage":2:{i:0;a:2:{i:0;O:8:"stdClass":1:{s:1:"x";i:1;}i:1;i:42;}i:1;a:0:{}}';
$u = unserialize($wire);
echo count($u), "\n";
$u->rewind();
if ($u->valid()) {
    $obj = $u->current();
    echo isset($obj->x) ? (string) $obj->x : '?', "\n";
}
