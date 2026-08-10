--TEST--
Stdlib: loadHTML opaque text for script/style/textarea (libxml htmlReadMemory; #29799)
--FILE--
<?php
$flags = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD;

$d = new DOMDocument();
@$d->loadHTML('<script>if (a < b) {}</script>', $flags);
echo 'script_lt=', var_export($d->documentElement->textContent, true), "\n";

$d = new DOMDocument();
@$d->loadHTML('<script>a &lt; b &amp; c</script>', $flags);
echo 'script_ent=', var_export($d->documentElement->textContent, true), "\n";

$d = new DOMDocument();
@$d->loadHTML('<style>a < b {}</style>', $flags);
echo 'style_save=', var_export(trim($d->saveHTML($d->documentElement)), true), "\n";

$d = new DOMDocument();
@$d->loadHTML('<textarea>a < b</textarea>', $flags);
echo 'textarea_save=', var_export(trim($d->saveHTML($d->documentElement)), true), "\n";

$d = new DOMDocument();
@$d->loadHTML('<title>a &lt; b</title>', $flags);
echo 'title_text=', var_export($d->documentElement->textContent, true), "\n";
--EXPECT--
script_lt='if (a < b) {}'
script_ent='a &lt; b &amp; c'
style_save='<style>a < b {}</style>'
textarea_save='<textarea>a &lt; b</textarea>'
title_text='a < b'
