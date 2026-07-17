<?php

declare(strict_types=1);

/**
 * #20260 — loadHTML must decode HTML character references in text/attrs (libxml htmlReadMemory).
 */
$doc = new DOMDocument();
@$doc->loadHTML('<!DOCTYPE html><html><body><p title="A&amp;B caf&eacute; &lt;x&gt;">A&amp;B caf&eacute; &lt;x&gt;</p></body></html>');
$p = $doc->getElementsByTagName('p')->item(0);
if (null === $p) {
    echo "fail: missing p\n";
    exit(1);
}
$expected = "A&B café <x>";
$text = $p->textContent;
$title = $p->getAttribute('title');
if ($text !== $expected) {
    echo 'fail: textContent=', json_encode($text), ' expected=', json_encode($expected), "\n";
    exit(1);
}
if ($title !== $expected) {
    echo 'fail: title=', json_encode($title), ' expected=', json_encode($expected), "\n";
    exit(1);
}

echo "ok\n";
