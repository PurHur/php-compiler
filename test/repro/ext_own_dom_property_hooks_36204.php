<?php
// Dom property fetch + instanceof stand-ins owned by Module::jitInit hooks (#36204).
$doc = new DOMDocument();
$doc->loadXML('<r><c a="1">t</c></r>');
$root = $doc->documentElement;
echo 'tag:', $root->tagName, ' kids:', $root->childNodes->length, "\n";
$text = $root->firstChild->firstChild;
echo 'text:', $text->textContent, ' isText:', $text instanceof DOMText ? 'y' : 'n', "\n";
echo 'attr:', $root->firstChild->getAttribute('a'), "\n";
