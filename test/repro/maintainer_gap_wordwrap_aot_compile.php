<?php

declare(strict_types=1);

/**
 * Maintainer repro: wordwrap() AOT compile must not lose LLVM insert block (#14565 regression).
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(wordwrap)
 */

$code = <<<'PHP'
<?php
$s = 'The quick brown fox jumped over the lazy dog.';
echo wordwrap($s, 20, "\n"), "\n";
echo wordwrap('supercalifragilistic', 5, '|', true), "\n";
PHP;

$tmp = tempnam(sys_get_temp_dir(), 'phpc_ww_aot_');
if (false === $tmp) {
    fwrite(STDERR, "fail: tempnam\n");
    exit(1);
}
file_put_contents($tmp, $code);

$repo = dirname(__DIR__, 2);
$proc = proc_open(
    ['php', $repo.'/bin/compile.php', '-l', $tmp],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $repo
);
if (!\is_resource($proc)) {
    fwrite(STDERR, "fail: proc_open\n");
    exit(1);
}
fclose($pipes[0]);
fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);
$exit = proc_close($proc);
@unlink($tmp);

if (0 !== $exit) {
    fwrite(STDERR, 'fail: compile -l exit '.$exit.' '.trim((string) $stderr)."\n");
    exit(1);
}

echo "ok\n";
