<?php
// #26323 — AOT runtime: symlink() returns bool true on success.
$l = '/tmp/phpc_symlink_26323.lnk';
@unlink($l);
$ok = symlink('/etc/hostname', $l);
var_dump($ok);
@unlink($l);
