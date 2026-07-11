<?php

declare(strict_types=1);

// Maintainer gap: DOMDocument::createProcessingInstruction() (#6318, ext/dom/php_dom.c).
$doc = new DOMDocument();
$root = $doc->createElement('root');
$doc->appendChild($root);
$root->appendChild($doc->createCDATASection('<raw>'));
$pi = $doc->createProcessingInstruction('php', 'echo 1;');
$doc->appendChild($pi);

if ('DOMProcessingInstruction' !== $pi::class) {
    fwrite(STDERR, "fail: expected DOMProcessingInstruction\n");
    exit(1);
}
if ('php' !== $pi->target || 'echo 1;' !== $pi->data) {
    fwrite(STDERR, "fail: target/data mismatch\n");
    exit(1);
}
$serialized = $doc->saveXML($pi);
if ('<?php echo 1;?>' !== $serialized) {
    fwrite(STDERR, 'fail: saveXML(pi) expected <?php echo 1;?>, got '.var_export($serialized, true)."\n");
    exit(1);
}

echo "ok\n";
