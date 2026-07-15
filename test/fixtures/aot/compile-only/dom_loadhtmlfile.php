<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadHTMLFile('/tmp/dom_loadhtmlfile_aot_fixture.html');
echo "loadhtmlfile_ok\n";
