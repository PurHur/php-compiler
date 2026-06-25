<?php

declare(strict_types=1);

// Issue #11433 — get_meta_tags() on data:// URI (ext/standard/php_meta_tags.c).
$html = '<html><head><meta name="author" content="me"></head></html>';
$uri = 'data://text/plain,'.$html;
$tags = get_meta_tags($uri);
echo (is_array($tags) && isset($tags['author']) && 'me' === $tags['author']) ? "tags_ok\n" : "tags_fail\n";
