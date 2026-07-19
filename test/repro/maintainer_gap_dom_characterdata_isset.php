<?php
/**
 * Dom\Text/Comment isset(nodeValue/data/textContent/length) while readable (#21055).
 * php-src: ext/dom/characterdata.c / php_dom.stub.php
 */
$html = '<html><body><div>a<!--c-->b</div></body></html>';
$doc = Dom\HTMLDocument::createFromString($html);
$div = $doc->body->firstElementChild;
$text = $div->firstChild;
$comment = $text->nextSibling;
echo 'text_nodeValue=', var_export($text->nodeValue, true), "\n";
echo 'isset_text_nodeValue=', var_export(isset($text->nodeValue), true), "\n";
echo 'isset_text_textContent=', var_export(isset($text->textContent), true), "\n";
echo 'isset_text_data=', var_export(isset($text->data), true), "\n";
echo 'isset_text_length=', var_export(isset($text->length), true), "\n";
echo 'comment_data=', var_export($comment->data, true), "\n";
echo 'isset_comment_nodeValue=', var_export(isset($comment->nodeValue), true), "\n";
echo 'isset_comment_data=', var_export(isset($comment->data), true), "\n";
echo 'isset_comment_textContent=', var_export(isset($comment->textContent), true), "\n";
echo 'isset_comment_length=', var_export(isset($comment->length), true), "\n";
