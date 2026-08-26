<?php
$dom = new DOMDocument();
$dom->loadXML('<!DOCTYPE html><html><body>x</body></html>');
echo $dom->saveXML();
