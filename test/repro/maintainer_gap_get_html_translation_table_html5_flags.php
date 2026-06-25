<?php

declare(strict_types=1);

try {
    $table = get_html_translation_table(HTML_ENTITIES, ENT_QUOTES | ENT_HTML5);
    $quote = $table['"'] ?? null;
    if ('&quot;' !== $quote) {
        echo "fail: quote=".var_export($quote, true)."\n";
        exit(1);
    }
    echo "ok quote='".$quote."'\n";
} catch (Throwable $e) {
    echo 'fail: '.get_class($e).': '.$e->getMessage()."\n";
    exit(1);
}
