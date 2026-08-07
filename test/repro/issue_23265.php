<?php

declare(strict_types=1);

/**
 * Issue #23265 — htmlspecialchars_decode / html_entity_decode Reflection $flags
 * (InternalArgInfo still says quote_style) + Zend named args.
 */
foreach (['htmlspecialchars_decode', 'html_entity_decode'] as $fn) {
    $r = new ReflectionFunction($fn);
    $names = [];
    foreach ($r->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ':', implode(',', $names), "\n";
}

echo 'hsd=', htmlspecialchars_decode(string: '&amp;', flags: ENT_QUOTES), "\n";
echo 'hed=', html_entity_decode(string: '&amp;', flags: ENT_QUOTES, encoding: 'UTF-8'), "\n";

try {
    htmlspecialchars_decode(string: '&amp;', quote_style: ENT_QUOTES);
    echo "quote_style_accepted\n";
} catch (Throwable $e) {
    echo 'quote_style=', $e->getMessage(), "\n";
}
