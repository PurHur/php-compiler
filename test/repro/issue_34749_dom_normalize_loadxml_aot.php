<?php
$d = new DOMDocument();
$d->loadXML('<r>a</r>');
$d->documentElement->normalize();
echo $d->documentElement->textContent, "\n";
