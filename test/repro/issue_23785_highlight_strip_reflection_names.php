<?php

declare(strict_types=1);

// Issue #23785 - highlight Reflection names and named filename args.
foreach (['highlight_string', 'highlight_file', 'php_strip_whitespace', 'show_source'] as $f) {
    $r = new ReflectionFunction($f);
    $parts = [];
    foreach ($r->getParameters() as $p) {
        $d = $p->isDefaultValueAvailable()
            ? '=' . json_encode($p->getDefaultValue())
            : ($p->isOptional() ? '=?' : '');
        $parts[] = $p->getName() . $d;
    }
    echo $f . ': ' . implode(', ', $parts) . "\n";
}
try {
    echo 'hs_named=' . (is_string(highlight_string(string: '<?php', return: true)) ? 'ok' : 'bad') . "\n";
} catch (Throwable $e) {
    echo 'hs_named=EX:' . $e->getMessage() . "\n";
}
try {
    echo 'psw_named=' . (is_string(php_strip_whitespace(filename: __FILE__)) ? 'ok' : 'bad') . "\n";
} catch (Throwable $e) {
    echo 'psw_named=EX:' . $e->getMessage() . "\n";
}
