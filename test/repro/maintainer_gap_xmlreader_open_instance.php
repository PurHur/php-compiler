<?php
/**
 * Issue #19330 repro — XMLReader::open() instance call ($this at arg0, URI at arg1).
 */
$path = '/tmp/phpc_xr_open_instance.xml';
file_put_contents($path, '<root><a>1</a></root>');
$r = new XMLReader();
$ok = $r->open($path);
echo (int) $ok, "\n";
$names = [];
while ($r->read()) {
    if ($r->nodeType === XMLReader::ELEMENT) {
        $names[] = $r->name;
    }
}
echo implode(',', $names), "\n";
