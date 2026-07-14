<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->load('/tmp/dom_document_load_aot_fixture.xml');
echo "load_ok\n";
