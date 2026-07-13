<?php
declare(strict_types=1);

try {
    get_html_translation_table(null);
    echo "FAIL expected TypeError\n";
    exit(1);
} catch (TypeError $e) {
    echo "ok\n";
    exit(0);
}
