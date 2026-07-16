<?php

declare(strict_types=1);

foreach ([
    'comment' => '<!--c--><root/>',
    'pi' => '<?pi target data?><root/>',
] as $label => $xml) {
    $dom = new DOMDocument();
    $ok = @$dom->loadXML($xml);
    echo $label, ': ok=', var_export($ok, true), ' children=', $dom->childNodes->length, "\n";
    foreach ($dom->childNodes as $n) {
        echo '  ', $n->nodeName, ':', $n->nodeType;
        if ($n instanceof DOMComment) {
            echo ' data=', $n->data;
        }
        if ($n instanceof DOMProcessingInstruction) {
            echo ' data=', $n->data;
        }
        echo "\n";
    }
}
