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
// if/else — AOT `echo ($x===false)?'f':(string)$x->code` else-arm variable is empty (#29161 / ?: echo)
if ($last === false) {
    echo "last=f\n";
} else {
    echo 'last=', $last->code, "\n";
}
libxml_clear_errors();
echo 'after=', count(libxml_get_errors()), "\n";
