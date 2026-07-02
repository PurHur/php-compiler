<?php

declare(strict_types=1);

$script = <<<'PHP'
<?php
function g(): Generator {
    yield 1;
    throw new Exception('x');
}
$g = g();
$g->next();
PHP;

$tmp = tempnam(sys_get_temp_dir(), 'gen-throw-resume-');
if (false === $tmp) {
    fwrite(STDERR, "fail: tempnam\n");
    exit(1);
}
file_put_contents($tmp, $script);
$output = shell_exec(PHP_BINARY.' '.escapeshellarg($tmp).' 2>&1');
@unlink($tmp);

if (!is_string($output) || !str_contains($output, '[internal function]: g()')) {
    fwrite(STDERR, "fail: expected '[internal function]: g()' in fatal trace\n{$output}\n");
    exit(1);
}

echo "ok\n";
