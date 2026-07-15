<?php

declare(strict_types=1);

$literal = (static function (): string {
    ob_start();
    var_export(null);
    return (string) ob_get_clean();
})();

$variable = (static function (): string {
    $v = null;
    ob_start();
    var_export($v);
    return (string) ob_get_clean();
})();

if ($literal !== 'NULL') {
    fwrite(STDERR, "literal: expected NULL, got {$literal}\n");
    exit(1);
}

if ($variable !== 'NULL') {
    fwrite(STDERR, "variable: expected NULL, got {$variable}\n");
    exit(1);
}

echo "ok\n";
