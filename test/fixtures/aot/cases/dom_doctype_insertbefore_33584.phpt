--TEST--
AOT: insertBefore(DocumentType, documentElement) saveXML matches Zend (#33584)
--FILE--
<?php
$impl = new DOMImplementation();
$dt = $impl->createDocumentType('html');
$d = new DOMDocument();
$e = $d->createElement('html');
$d->appendChild($e);
$d->insertBefore($dt, $e);
echo $d->saveXML();
--EXPECT--
<?xml version="1.0"?>
<!DOCTYPE html>
<html/>
