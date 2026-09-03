<?php
// Layout seeders owned by Module::jitInit (#36204): tokenizer / openssl / dom / xmlreader.
// Avoid `new PhpToken(...)` — segfaults on master too; tokenize materializes props (#27263).
// PROFILE=8.4 for DOCUMENT_POSITION_*.

$tokens = PhpToken::tokenize('<?php echo 1;');
echo 'tok:', count($tokens), ' id:', (int) $tokens[0]->id, "\n";

$doc = new DOMDocument();
$el = $doc->createElement('a');
$doc->appendChild($el);
echo 'dom:', $doc->documentElement->tagName, ' base:', property_exists($doc, 'baseURI') ? 'y' : 'n', "\n";
echo 'pos:', (int) DOMNode::DOCUMENT_POSITION_DISCONNECTED, "\n";

// Print XMLReader consts before other XMLReader traffic (AOT quirk matches master).
echo 'xr:', (int) XMLReader::ELEMENT, "\n";
$xr = new XMLReader();
echo 'method:', method_exists($xr, 'read') ? 'y' : 'n', "\n";

echo 'ossl:', class_exists('OpenSSLAsymmetricKey') ? 'y' : 'n', "\n";
