<?php
// Zend parity: DOMXPath::evaluate() invalid expression → warning + false (ext/dom/xpath.c).
// Companion to query()-only tracker #22721.
$dom = new DOMDocument();
$dom->loadXML('<r/>');
$xp = new DOMXPath($dom);
set_error_handler(static function (int $no, string $str): bool {
    echo 'WARN:', $str, "\n";
    return true;
});
$bad = $xp->evaluate('@@@');
echo 'result=', var_export($bad, true), "\n";
