<?php
/**
 * Parsedown tight list: unset($Elements[0]['name']) must strip <p> wrapper (#36380).
 */
require 'test/apps/erusev-parsedown/pinned/Parsedown.php';

$p = new Parsedown();
$out = $p->text("- one\n- two\n");
$exp = "<ul>\n<li>one</li>\n<li>two</li>\n</ul>";
echo ($out === $exp) ? "OK\n" : ("BAD\n" . $out);
