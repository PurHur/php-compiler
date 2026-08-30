<?php

declare(strict_types=1);

$r = XMLReader::XML('<root xml:lang="en"><child/></root>');
$r->read();
echo 'rootLang=', $r->xmlLang, "\n";
$r->read();
echo 'childLang=', $r->xmlLang, "\n";
