<?php
// AOT: DOMDocument::saveHTMLFile must write saveHTML bytes (#35549 leftover of #18268 / #35546).
$d = new DOMDocument();
$d->loadXML('<r>hi</r>');
$path = sys_get_temp_dir() . '/phpc_dom_savehtmlfile_' . getmypid() . '.html';
@unlink($path);
$n = $d->saveHTMLFile($path);
var_export($n);
echo "\n";
if (is_int($n) && is_file($path)) {
    echo trim(file_get_contents($path)), "\n";
    echo 'len=', filesize($path), "\n";
} else {
    echo "missing\n";
}
@unlink($path);
