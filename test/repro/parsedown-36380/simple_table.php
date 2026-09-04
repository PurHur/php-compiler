<?php
require __DIR__ . '/../../apps/erusev-parsedown/pinned/Parsedown.php';
require __DIR__ . '/../../apps/erusev-parsedown/pinned/test/TestParsedown.php';
$P = new TestParsedown();
$md = file_get_contents(__DIR__ . '/../../apps/erusev-parsedown/pinned/test/data/simple_table.md');
$exp = file_get_contents(__DIR__ . '/../../apps/erusev-parsedown/pinned/test/data/simple_table.html');
$act = $P->text($md);
echo "TEST=simple_table\n";
echo "MATCH=" . ($act === $exp ? "yes" : "no") . "\n";
echo "---ACT---\n";
echo $act;
echo "\n---EXP---\n";
echo $exp;
echo "\n---END---\n";
