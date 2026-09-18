<?php
require dirname(__DIR__, 2) . '/test/apps/erusev-parsedown/pinned/Parsedown.php';
$p = new Parsedown();
// two trailing spaces then newline then text
$md = "a  \nb\n";
echo $p->text($md);
