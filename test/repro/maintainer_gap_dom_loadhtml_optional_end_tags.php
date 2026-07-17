<?php

declare(strict_types=1);

/**
 * #20247 — loadHTML must auto-close HTML optional end tags (libxml htmlReadMemory).
 */
$doc = new DOMDocument();
if (!$doc->loadHTML('<p>hi<br><img src="x">')) {
    echo "fail: loadHTML returned false\n";
    exit(1);
}
if (1 !== $doc->getElementsByTagName('p')->length) {
    echo "fail: expected 1 p got ".$doc->getElementsByTagName('p')->length."\n";
    exit(1);
}
if (1 !== $doc->getElementsByTagName('br')->length || 1 !== $doc->getElementsByTagName('img')->length) {
    echo "fail: void children missing under unclosed p\n";
    exit(1);
}

$doc = new DOMDocument();
$doc->loadHTML('<p>a<p>b');
if (2 !== $doc->getElementsByTagName('p')->length) {
    echo "fail: p-p expected 2 p\n";
    exit(1);
}

$doc = new DOMDocument();
$doc->loadHTML('<ul><li>a<li>b</ul>');
if (2 !== $doc->getElementsByTagName('li')->length) {
    echo "fail: li-li expected 2 li\n";
    exit(1);
}

$doc = new DOMDocument();
$doc->loadHTML('<table><tr><td>a<td>b</tr></table>');
if (2 !== $doc->getElementsByTagName('td')->length) {
    echo "fail: td-td expected 2 td\n";
    exit(1);
}

$doc = new DOMDocument();
$doc->loadHTML('<body><p>hi</body>');
if (1 !== $doc->getElementsByTagName('p')->length) {
    echo "fail: unclosed-p-in-body expected 1 p\n";
    exit(1);
}

echo "ok optional-end-tags\n";
