--TEST--
AOT libxml_clear_errors/get_errors/get_last_error after malformed loadXML (#29161)
--FILE--
<?php
libxml_use_internal_errors(true);
libxml_clear_errors();
$d = new DOMDocument();
$ok = $d->loadXML('<r>');
echo 'load=', $ok ? '1' : '0', "\n";
$errs = libxml_get_errors();
echo 'n=', count($errs), "\n";
if (count($errs) > 0) {
    echo 'code=', $errs[0]->code, "\n";
}
$last = libxml_get_last_error();
if ($last === false) {
    echo "last=f\n";
} else {
    echo 'last=', $last->code, "\n";
}
libxml_clear_errors();
echo 'after=', count(libxml_get_errors()), "\n";
--EXPECT--
load=0
n=1
code=77
last=77
after=0
