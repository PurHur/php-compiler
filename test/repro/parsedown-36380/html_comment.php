<?php
require __DIR__ . '/../../apps/erusev-parsedown/pinned/Parsedown.php';
require __DIR__ . '/../../apps/erusev-parsedown/pinned/test/TestParsedown.php';
$P = new TestParsedown();
$md = file_get_contents(__DIR__ . '/../../apps/erusev-parsedown/pinned/test/data/html_comment.md');
$exp = file_get_contents(__DIR__ . '/../../apps/erusev-parsedown/pinned/test/data/html_comment.html');
$act = $P->text($md);
echo "TEST=html_comment\n";
echo "MATCH=" . ($act === $exp ? "yes" : "no") . "\n";
echo "---ACT---\n";
echo $act;
echo "\n---EXP---\n";
echo $exp;
echo "\n---END---\n";
