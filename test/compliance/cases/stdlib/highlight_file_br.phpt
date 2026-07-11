--TEST--
stdlib highlight_file() multi-line source uses <br /> not raw newlines in spans
--FILE--
<?php
$f = tempnam(sys_get_temp_dir(), 'hl');
file_put_contents($f, "line1\nline2\n");
$html = highlight_file($f, true);
unlink($f);
echo substr_count($html, '<br') === 2 ? "br-ok\n" : "br-bad\n";
echo strpos($html, "line1\nline2") !== false ? "raw-nl\n" : "no-raw-nl\n";
echo strpos($html, '<code>') !== false ? "code-wrap\n" : "no-code-wrap\n";
?>
--EXPECT--
br-ok
no-raw-nl
code-wrap
