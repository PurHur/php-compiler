<?php

declare(strict_types=1);

/**
 * #24319 — libxml premature-end must name the innermost open tag (php-src / libxml2).
 */
libxml_use_internal_errors(true);

$cases = [
    '<root><unclosed>',
    '<a><b>',
    '<a><b><c>',
    '<x><y></y>',
    '<root><a/><b>',
    "<root>\n<a>\n",
    '<root><!--x--><a>',
    '<root><unclosed', // #18332 secondary still names outer root
];

foreach ($cases as $xml) {
    libxml_clear_errors();
    $doc = new DOMDocument();
    $doc->loadXML($xml);
    $errors = libxml_get_errors();
    $parts = [];
    foreach ($errors as $e) {
        $parts[] = sprintf(
            'code=%d msg=%s line=%d col=%d',
            $e->code,
            json_encode(trim($e->message)),
            $e->line,
            $e->column
        );
    }
    echo json_encode($xml), ' => ', implode(' | ', $parts), "\n";
}

echo "ok\n";
