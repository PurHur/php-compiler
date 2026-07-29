--TEST--
get_html_translation_table / ob_get_status named args (VM, issue #23786)
--FILE--
<?php
$r = new ReflectionFunction('get_html_translation_table');
echo implode(',', array_map(static fn ($p) => $p->getName(), $r->getParameters())), PHP_EOL;
$t = get_html_translation_table(table: HTML_SPECIALCHARS);
echo var_export($t['"'] ?? null, true), PHP_EOL;
$r2 = new ReflectionFunction('ob_get_status');
echo implode(',', array_map(static fn ($p) => $p->getName(), $r2->getParameters())), PHP_EOL;
var_export(ob_get_status(full_status: false));
echo PHP_EOL;
--EXPECT--
table,flags,encoding
'&quot;'
full_status
array (
)
