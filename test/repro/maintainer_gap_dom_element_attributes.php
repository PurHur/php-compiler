<?php

declare(strict_types=1);

$doc = new DOMDocument();
$attrs = $doc->createElement('p')->attributes;
if (!($attrs instanceof DOMNamedNodeMap)) {
    fwrite(STDERR, 'DOMElement::attributes must be DOMNamedNodeMap, got '.var_export($attrs, true)."\n");
    exit(1);
}
if (0 !== $attrs->length) {
    fwrite(STDERR, 'expected empty attributes map, length='.$attrs->length."\n");
    exit(1);
}

$el = $doc->createElement('div');
$el->setAttribute('id', 'x');
if (1 !== $el->attributes->length) {
    fwrite(STDERR, 'expected length 1 after setAttribute, got '.$el->attributes->length."\n");
    exit(1);
}

echo "dom_element_attributes ok\n";
