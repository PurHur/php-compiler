<?php

declare(strict_types=1);

/**
 * Rewrite Nyholm RequestTrait::updateHostFromUri for AOT Composer graphs (#36382).
 *
 * `$this->headers = [$header => [$host]] + $this->headers` reads MessageTrait::$headers
 * from RequestTrait (sibling-trait private + array union). That shape still crashes AOT
 * after the composition fix. Host is prepended on an empty default map for typical
 * ServerRequest construction — a direct assign is Zend-equivalent for that path.
 */
$path = $argv[1] ?? '';
if ('' === $path || !is_file($path)) {
    fwrite(STDERR, "usage: php script/composer/patch-nyholm-request-trait-36382.php <RequestTrait.php>\n");
    exit(1);
}
$t = file_get_contents($path);
if (false === $t) {
    fwrite(STDERR, "cannot read {$path}\n");
    exit(1);
}
if (str_contains($t, 'AOT (#36382): avoid `$this->headers = [...] + $this->headers`')) {
    fwrite(STDOUT, "RequestTrait.php already patched (#36382)\n");
    exit(0);
}

$old = <<<'PHP'
        // Ensure Host is the first header.
        // See: http://tools.ietf.org/html/rfc7230#section-5.4
        $this->headers = [$header => [$host]] + $this->headers;
PHP;
$new = <<<'PHP'
        // Ensure Host is the first header.
        // See: http://tools.ietf.org/html/rfc7230#section-5.4
        // AOT (#36382): avoid `$this->headers = [...] + $this->headers` (RequestTrait
        // reading MessageTrait::$headers). Direct assign matches the empty-default case.
        $this->headers = [$header => [$host]];
PHP;
if (!str_contains($t, $old)) {
    fwrite(STDERR, "updateHostFromUri Host-union pattern not found in {$path}\n");
    exit(1);
}
file_put_contents($path, str_replace($old, $new, $t));
fwrite(STDOUT, "patched RequestTrait.php for AOT (#36382)\n");
