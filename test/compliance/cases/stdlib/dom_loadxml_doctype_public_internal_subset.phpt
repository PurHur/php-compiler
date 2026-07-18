--TEST--
Stdlib: DOMDocument::loadXML() PUBLIC/SYSTEM doctype + internal subset (#20504, ext/dom/document.c)
--FILE--
<?php
$d = new DOMDocument();
$ok = $d->loadXML('<!DOCTYPE r PUBLIC "pub" "sys" [<!ELEMENT r EMPTY>]><r/>');
echo $ok ? "load:ok\n" : "load:fail\n";
echo null === $d->doctype ? "doctype:null\n" : "doctype:{$d->doctype->name}\n";
echo null === $d->doctype ? "" : "public:{$d->doctype->publicId}\n";
echo null === $d->doctype ? "" : "system:{$d->doctype->systemId}\n";

$d2 = new DOMDocument();
$ok2 = $d2->loadXML('<!DOCTYPE r SYSTEM "sys" [<!ELEMENT r EMPTY>]><r/>');
echo $ok2 && null !== $d2->doctype && $d2->doctype->systemId === 'sys' ? "system_subset:ok\n" : "system_subset:fail\n";
?>
--EXPECT--
load:ok
doctype:r
public:pub
system:sys
system_subset:ok
