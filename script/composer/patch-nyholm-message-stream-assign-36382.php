<?php

declare(strict_types=1);

/**
 * Rename `$this->stream` → `$this->bodyStream` on Nyholm Request/Response/ServerRequest (#36382).
 * Companion to patch-nyholm-message-trait-bodystream-36382.php.
 */
$path = $argv[1] ?? '';
if ('' === $path || !is_file($path)) {
    fwrite(STDERR, "usage: php script/composer/patch-nyholm-message-stream-assign-36382.php <Request|Response|ServerRequest.php>\n");
    exit(1);
}
$t = file_get_contents($path);
if (false === $t) {
    fwrite(STDERR, "cannot read {$path}\n");
    exit(1);
}
$base = basename($path);
if (str_contains($t, 'AOT (#36382): bodyStream assign')) {
    fwrite(STDOUT, "{$base} bodyStream assign already patched (#36382)\n");
    exit(0);
}
if (!str_contains($t, '$this->stream = Stream::create')) {
    fwrite(STDERR, "\$this->stream = Stream::create not found in {$path}\n");
    exit(1);
}
$t = str_replace(
    '$this->stream = Stream::create',
    // marker comment on same line would break; use preceding line inject via replace
    '$this->bodyStream = Stream::create',
    $t,
    $n
);
if ($n < 1) {
    fwrite(STDERR, "replace failed in {$path}\n");
    exit(1);
}
// Add marker once near create assign
$t = str_replace(
    '$this->bodyStream = Stream::create',
    '/* AOT (#36382): bodyStream assign */ $this->bodyStream = Stream::create',
    $t,
    $n2
);
if (false === file_put_contents($path, $t)) {
    fwrite(STDERR, "cannot write {$path}\n");
    exit(1);
}
fwrite(STDOUT, "patched {$base} stream→bodyStream assign for AOT (#36382)\n");
