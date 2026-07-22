--TEST--
stdlib DOMDocument::loadHTML() — @silence then trim(saveHTML()) fragment (#22345, re-#19360)
--FILE--
<?php
$d = new DOMDocument();
$ok = @$d->loadHTML('<p>x</p>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
echo 'ok=', var_export($ok, true), "\n";
echo 'html=', trim($d->saveHTML()), "\n";
--EXPECT--
ok=true
html=<p>x</p>
