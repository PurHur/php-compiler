<?php

declare(strict_types=1);

$doc = new DOMDocument();
$comment = $doc->createComment('note');
$doc->appendChild($comment);
if (!($doc->firstChild instanceof DOMComment)) {
    fwrite(STDERR, 'fail: firstChild should be DOMComment, got '.get_class($doc->firstChild)."\n");
    exit(1);
}
echo 'child_class: ', get_class($doc->firstChild), "\n";
echo 'doc_element: ', null === $doc->documentElement ? 'null' : 'set', "\n";

echo "ok\n";
