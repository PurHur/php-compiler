<?php
/**
 * Issue #19607 repro — XMLReader::fromString under PHP 8.4 profile.
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_xmlreader_from_factories.php
 */
$r = XMLReader::fromString('<root><a/></root>');
echo 'read=', $r->read() ? '1' : '0', ' name=', $r->name, "\n";
