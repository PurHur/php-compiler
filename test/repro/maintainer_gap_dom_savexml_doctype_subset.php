<?php

declare(strict_types=1);

$d = new DOMDocument();
$d->loadXML('<!DOCTYPE r [<!ENTITY e "E">]><r/>');
echo trim($d->saveXML($d->doctype))."\n";

$d2 = new DOMDocument();
$d2->loadXML('<!DOCTYPE r SYSTEM "sys.dtd"><r/>');
echo trim($d2->saveXML($d2->doctype))."\n";

$d3 = new DOMDocument();
$d3->loadXML('<!DOCTYPE r SYSTEM "sys.dtd" [<!ENTITY e "E">]><r/>');
echo trim($d3->saveXML($d3->doctype))."\n";
