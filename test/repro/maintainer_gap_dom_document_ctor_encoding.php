<?php
$doc = new DOMDocument('1.0', 'UTF-8');
$ok = true;
if ('UTF-8' !== $doc->encoding) {
    $ok = false;
}
if ('1.0' !== $doc->xmlVersion) {
    $ok = false;
}
$xml = $doc->saveXML();
if (!str_contains($xml, 'encoding="UTF-8"')) {
    $ok = false;
}
echo $ok ? "OK\n" : "FAIL enc={$doc->encoding} ver={$doc->xmlVersion} xml=$xml\n";
exit($ok ? 0 : 1);
