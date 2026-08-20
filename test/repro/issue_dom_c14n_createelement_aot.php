<?php
$d = new DOMDocument();
$el = $d->createElement('r');
$el->setAttribute('a', '1');
$d->appendChild($el);
echo $el->C14N();
echo "\n";
$p = sys_get_temp_dir().'/phpc_c14n_ce_32973_'.getmypid().'.xml';
@unlink($p);
var_dump($el->C14NFile($p));
echo file_get_contents($p);
echo "\n";
@unlink($p);
