<?php
use Dom\XMLDocument;
$d = XMLDocument::createFromString('<r><a id="x"><b>t</b></a></r>');
$a = $d->querySelector('a');
echo 'qs=', $a ? $a->localName : 'null', "\n";
echo 'qsa=', $d->querySelectorAll('b')->length, "\n";
