<?php

declare(strict_types=1);

/**
 * Maintainer gap repro named in #19940 — XMLReader::setRelaxNGSchemaSource().
 */
$grammar = '<grammar xmlns="http://relaxng.org/ns/structure/1.0"><start><element name="r"><text/></element></start></grammar>';

$x = new XMLReader();
echo 'method=', method_exists($x, 'setRelaxNGSchemaSource') ? '1' : '0', "\n";

$x->XML('<r>ok</r>');
echo 'set=', (int) $x->setRelaxNGSchemaSource($grammar), "\n";
while ($x->read()) {
}
echo 'valid=', (int) $x->isValid(), "\n";
