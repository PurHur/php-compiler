<?php
require __DIR__ . '/../apps/erusev-parsedown/pinned/Parsedown.php';
require __DIR__ . '/../apps/erusev-parsedown/pinned/test/TestParsedown.php';
$p = new TestParsedown();
foreach ([
  '_underscore_',
  '*asterisk*',
  '[link](http://example.com)',
  "    indented code\n",
] as $md) {
  $act = $p->text($md);
  echo 'IN=[' . str_replace("\n", '\n', $md) . '] OUT=[' . str_replace("\n", '\n', $act) . "]\n";
}
