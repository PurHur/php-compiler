<?php
declare(strict_types=1);

/**
 * AOT DOMText::wholeText after createTextNode must match data (#32395).
 * php-src ext/dom/text.c dom_text_whole_text_read.
 */
$doc = new DOMDocument();
$text = $doc->createTextNode('ab');
echo $text->wholeText, "\n";
