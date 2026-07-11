<?php
declare(strict_types=1);
/**
 * Maintainer repro: Dom\HTMLDocument living-standard namespace (#6506).
 *
 * Zend 8.4+: Dom\HTMLDocument::createFromString('<p>hi</p>')->body->textContent === 'hi'
 */
$classes = [
    'Dom\\HTMLDocument',
    'Dom\\XMLDocument',
    'Dom\\Document',
    'Dom\\Node',
    'Dom\\Element',
];
foreach ($classes as $class) {
    echo $class, ': ', class_exists($class) ? 'yes' : 'no', "\n";
}
if (!class_exists('Dom\\HTMLDocument')) {
    exit(1);
}
$doc = Dom\HTMLDocument::createFromString('<p>hi</p>');
$text = $doc->body->textContent;
echo 'body_text=', $text, "\n";
echo ($text === 'hi' ? 'dom_html_document ok' : 'dom_html_document fail'), "\n";
