--TEST--
AOT: DOMDocument::saveXML(options: int) named arg without $node (#32018 / re-#25182)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r/>');
echo 'saveXML_opts=', (str_contains($d->saveXML(options: 0), '<r') ? 'ok' : 'bad'), "\n";
echo 'saveXML_null=', (str_contains($d->saveXML(node: null), '<r') ? 'ok' : 'bad'), "\n";
--EXPECT--
saveXML_opts=ok
saveXML_null=ok
