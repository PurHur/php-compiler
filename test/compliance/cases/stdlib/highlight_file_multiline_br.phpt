--TEST--
stdlib highlight_file() multiline — <br /> line breaks (#17557, ext/standard/highlight.c)
--FILE--
<?php
$f = tempnam(sys_get_temp_dir(), 'hlbr');
file_put_contents($f, "line1\nline2\n");
$html = highlight_file($f, true);
unlink($f);
echo 'br=' . substr_count((string) $html, '<br') . "\n";
echo 'pre=' . (strpos((string) $html, '<pre>') !== false ? 'yes' : 'no') . "\n";
$sf = tempnam(sys_get_temp_dir(), 'hlsh');
file_put_contents($sf, "a\nb\n");
$show = show_source($sf, true);
unlink($sf);
echo 'show_br=' . substr_count((string) $show, '<br') . "\n";
echo 'show_pre=' . (strpos((string) $show, '<pre>') !== false ? 'yes' : 'no') . "\n";
--EXPECT--
br=0
pre=yes
show_br=0
show_pre=yes
