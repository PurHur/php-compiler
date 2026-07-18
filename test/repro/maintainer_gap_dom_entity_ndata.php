<?php
declare(strict_types=1);

$xml = '<!DOCTYPE r ['
    . '<!ENTITY e SYSTEM "http://example/x" NDATA gif>'
    . '<!ENTITY f "text">'
    . '<!ENTITY g PUBLIC "-//ex//EN" "http://example/g" NDATA gif>'
    . '<!ENTITY h SYSTEM "http://example/h">'
    . '<!NOTATION gif SYSTEM "image/gif">'
    . ']><r>&f;</r>';

$d = new DOMDocument();
@$d->loadXML($xml);
$dt = $d->doctype;
echo 'entities_len=', $dt->entities->length, "\n";
echo 'notations_len=', $dt->notations->length, "\n";

foreach (['e', 'f', 'g', 'h'] as $name) {
    $ent = $dt->entities->getNamedItem($name);
    if (null === $ent) {
        echo "named_{$name}=null\n";
        continue;
    }
    echo "named_{$name}=", $ent->nodeName;
    echo ' publicId=', var_export($ent->publicId, true);
    echo ' systemId=', var_export($ent->systemId, true);
    echo ' notationName=', var_export($ent->notationName, true);
    echo "\n";
}

$not = $dt->notations->getNamedItem('gif');
echo 'notation_gif=', $not ? $not->nodeName : 'null';
if ($not) {
    echo ' systemId=', var_export($not->systemId, true);
}
echo "\n";
