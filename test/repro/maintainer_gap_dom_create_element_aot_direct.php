<?php

declare(strict_types=1);

$doc = new DOMDocument();
echo ($doc->createElement('p') === null) ? "null\n" : "obj\n";
