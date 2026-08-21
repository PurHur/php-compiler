--TEST--
AOT: appendChild(DocumentType)+Element saveXML emits <!DOCTYPE> (#33584)
--FILE--
<?php
$impl = new DOMImplementation();
$dt = $impl->createDocumentType('html');
$d = new DOMDocument();
$d->appendChild($dt);
$e = $d->createElement('html');
$d->appendChild($e);
echo $d->saveXML();
--EXPECT--
<?xml version="1.0"?>
<!DOCTYPE html>
<html/>
