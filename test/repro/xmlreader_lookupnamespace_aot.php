<?php
declare(strict_types=1);
/** AOT: XMLReader::lookupNamespace leftover of fromString/getAttribute (#35930 / #27299). */
$r = XMLReader::fromString('<r xmlns:p="urn:x" p:a="1"><c xmlns:q="urn:q"/></r>');
$r->read();
var_export($r->lookupNamespace('p'));
echo PHP_EOL;
var_export($r->lookupNamespace('z'));
echo PHP_EOL;
$r->read();
var_export($r->lookupNamespace('p'));
echo PHP_EOL;
var_export($r->lookupNamespace('q'));
echo PHP_EOL;
