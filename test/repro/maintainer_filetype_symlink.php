<?php
$p = '/tmp/phpc-filetype-link';
@unlink($p);
symlink('/etc/hosts', $p);
echo filetype($p), "\n";
echo is_link($p) ? "is_link yes\n" : "is_link no\n";
@unlink($p);
