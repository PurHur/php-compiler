<?php
$h = Dom\HTMLDocument::createFromString("<!doctype html><html><body></body></html>");
echo $h->head === null ? "NULL" : $h->head->tagName, "\n";
$h->title = "Hi";
echo json_encode($h->title), "\n";
echo $h->saveHtml(), "\n";
