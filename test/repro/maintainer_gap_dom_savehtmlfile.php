<?php

declare(strict_types=1);

// AOT: DOMDocument::saveHTMLFile() after loadHTML compile-time literal (#18268).
$d = new DOMDocument();
$d->loadHTML("<p>hi</p>");
$d->saveHTMLFile('/tmp/dom_savehtmlfile_aot.html');
echo "ok\n";
