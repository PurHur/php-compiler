<?php
require __DIR__ . '/../apps/erusev-parsedown/pinned/Parsedown.php';
$p = new Parsedown();
echo $p->text("# hi\n");
