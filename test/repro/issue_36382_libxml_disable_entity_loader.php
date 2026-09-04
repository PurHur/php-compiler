<?php
// #36382 — libxml_disable_entity_loader soft AOT (Slim BodyParsingMiddleware dead branch).
// php-src: ext/libxml/libxml.c PHP_FUNCTION(libxml_disable_entity_loader)
$prev = libxml_disable_entity_loader(true);
echo $prev ? 'true' : 'false', PHP_EOL;
