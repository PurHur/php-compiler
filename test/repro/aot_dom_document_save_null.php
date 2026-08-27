<?php
// AOT: DOMDocument::save must not be NULL (#35546 leftover of #18435 / #35540).
$d = new DOMDocument();
$d->loadXML('<r>hi</r>');
$path = sys_get_temp_dir() . '/phpc_dom_save_' . getmypid() . '.xml';
@unlink($path);
$n = $d->save($path);
var_export($n);
echo "\n";
if (is_int($n) && is_file($path)) {
    echo trim(file_get_contents($path)), "\n";
} else {
    echo "missing\n";
}
@unlink($path);
$fail = @$d->save('/nonexistent/path/phpc_dom_save_fail.xml');
var_export($fail);
echo "\n";
