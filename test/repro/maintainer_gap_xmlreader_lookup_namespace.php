<?php
/** Maintainer gap: XMLReader::lookupNamespace (#19396). */
$r = new XMLReader();
$r->XML('<r xmlns:p="urn:x" p:a="1"><c xmlns:q="urn:q"/></r>');
$r->read(); // r
echo 'lookupNs=', var_export($r->lookupNamespace('p'), true), "\n";
echo 'unknown=', var_export($r->lookupNamespace('z'), true), "\n";
$r->read(); // c
echo 'onC_p=', var_export($r->lookupNamespace('p'), true), "\n";
echo 'onC_q=', var_export($r->lookupNamespace('q'), true), "\n";
