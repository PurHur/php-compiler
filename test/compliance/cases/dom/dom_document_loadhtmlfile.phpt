--TEST--
stdlib DOMDocument::loadHTMLFile() — file-path HTML load (#18734, ext/dom/php_dom.c)
--FILE--
<?php
$tmp = tempnam(sys_get_temp_dir(), 'domlf');
file_put_contents($tmp, '<p id="x">hi</p>');
$doc = new DOMDocument();
$ok = $doc->loadHTMLFile($tmp);
$found = $doc->getElementById('x');
unlink($tmp);
echo ($ok ? '1' : '0'), "\n";
echo null === $found ? "null\n" : $found->textContent."\n";
?>
--EXPECT--
1
hi
