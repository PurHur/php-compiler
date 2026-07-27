--TEST--
stdlib highlight_file() multi-line source preserves raw newlines in Zend 8.4 <pre> wrapper (#23733, ext/standard/highlight.c)
--FILE--
<?php
$f = tempnam(sys_get_temp_dir(), 'hl');
file_put_contents($f, "line1\nline2\n");
$html = highlight_file($f, true);
unlink($f);
echo substr_count($html, '<br') === 0 ? "br-ok\n" : "br-bad\n";
echo strpos($html, "line1\nline2") !== false ? "raw-nl\n" : "no-raw-nl\n";
echo strpos($html, '<pre>') !== false ? "pre-wrap\n" : "no-pre-wrap\n";
?>
--EXPECT--
br-ok
raw-nl
pre-wrap
