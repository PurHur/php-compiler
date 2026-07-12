<?php

declare(strict_types=1);

$doc = new DOMDocument();
$text = $doc->createTextNode('hi');
var_export(property_exists($text, 'length'));
echo "\n";
var_export($text->length);
echo "\n";
var_export($text->data);
echo "\n";
$text->appendData(' there');
var_export($text->length);
echo "\n";
$comment = $doc->createComment('note');
var_export($comment->length);
echo "\n";
