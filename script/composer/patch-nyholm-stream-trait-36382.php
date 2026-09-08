<?php

declare(strict_types=1);

/**
 * Collapse Nyholm StreamTrait.php version if/else for AOT Composer graphs (#36382).
 *
 * The upstream file is:
 *
 *   if (\PHP_VERSION_ID >= 70400 || (new \ReflectionMethod(...))->hasReturnType()) {
 *       trait StreamTrait { public function __toString(): string { ... } }
 *   } else {
 *       trait StreamTrait { public function __toString() { ... } }
 *   }
 *
 * Under incremental IncludeHelper AOT, the true arm of that top-level if lowers to a
 * premature `ret void` on the caller `{main}` (reachable CFG dies at StreamTrait —
 * 11th unit in the Slim graph). The binary then exits 0 with empty stdout before the
 * entry body (`echo B1`, AppFactory::create, …) runs.
 *
 * php-compiler targets PHP 8.2+, so keep only the typed `__toString(): string` trait
 * (Zend-equivalent on this runtime).
 */
$path = $argv[1] ?? '';
if ('' === $path || !is_file($path)) {
    fwrite(STDERR, "usage: php script/composer/patch-nyholm-stream-trait-36382.php <StreamTrait.php>\n");
    exit(1);
}
$t = file_get_contents($path);
if (false === $t) {
    fwrite(STDERR, "cannot read {$path}\n");
    exit(1);
}

$old = <<<'PHP'
if (\PHP_VERSION_ID >= 70400 || (new \ReflectionMethod(StreamInterface::class, '__toString'))->hasReturnType()) {
    /**
     * @internal
     */
    trait StreamTrait
    {
        public function __toString(): string
        {
            if ($this->isSeekable()) {
                $this->seek(0);
            }

            return $this->getContents();
        }
    }
} else {
    /**
     * @internal
     */
    trait StreamTrait
    {
        /**
         * @return string
         */
        public function __toString()
        {
            try {
                if ($this->isSeekable()) {
                    $this->seek(0);
                }

                return $this->getContents();
            } catch (\Throwable $e) {
                if (\is_array($errorHandler = \set_error_handler('var_dump'))) {
                    $errorHandler = $errorHandler[0] ?? null;
                }
                \restore_error_handler();

                if ($e instanceof \Error || $errorHandler instanceof SymfonyErrorHandler || $errorHandler instanceof SymfonyLegacyErrorHandler) {
                    return \trigger_error((string) $e, \E_USER_ERROR);
                }

                return '';
            }
        }
    }
}
PHP;

$new = <<<'PHP'
// AOT (#36382): collapse PHP_VERSION_ID StreamTrait if/else — true arm was `ret void`
// on the IncludeHelper caller `{main}` (Slim empty CGI). PHP 8.2+ always takes the
// typed `__toString(): string` branch under Zend.
/**
 * @internal
 */
trait StreamTrait
{
    public function __toString(): string
    {
        // AOT (#36382): prefer rewind over Stream::seek()'s isset($this->stream) /
        // (-1 === fseek) compare — that path SEGVs on thin-AOT php://memory after write
        // (drop AotStringStream36382; php-src ext/standard/streams.c php_stream_seek).
        \rewind($this->stream);

        return $this->getContents();
    }
}
PHP;

if (str_contains($t, 'AOT (#36382): collapse PHP_VERSION_ID StreamTrait if/else')) {
    // Already collapsed — upgrade __toString seek→rewind if still on the SEGV path.
    $oldToString = <<<'PHP'
    public function __toString(): string
    {
        if ($this->isSeekable()) {
            $this->seek(0);
        }

        return $this->getContents();
    }
PHP;
    $newToString = <<<'PHP'
    public function __toString(): string
    {
        // AOT (#36382): prefer rewind over Stream::seek()'s isset($this->stream) /
        // (-1 === fseek) compare — that path SEGVs on thin-AOT php://memory after write
        // (drop AotStringStream36382; php-src ext/standard/streams.c php_stream_seek).
        \rewind($this->stream);

        return $this->getContents();
    }
PHP;
    if (str_contains($t, 'prefer rewind over Stream::seek()') && str_contains($t, '\\rewind($this->stream)') && !str_contains($t, 'isSeekable()')) {
        fwrite(STDOUT, "StreamTrait.php already patched (#36382)\n");
        exit(0);
    }
    $oldToStringRewindGuarded = <<<'PHP'
    public function __toString(): string
    {
        // AOT (#36382): prefer rewind over Stream::seek()'s isset($this->stream) /
        // (-1 === fseek) compare — that path SEGVs on thin-AOT php://memory after write
        // (drop AotStringStream36382; php-src ext/standard/streams.c php_stream_seek).
        if ($this->isSeekable()) {
            \rewind($this->stream);
        }

        return $this->getContents();
    }
PHP;
    if (str_contains($t, $oldToStringRewindGuarded)) {
        $t = str_replace($oldToStringRewindGuarded, $newToString, $t);
        file_put_contents($path, $t);
        fwrite(STDOUT, "upgraded StreamTrait.php __toString unconditional rewind for AOT (#36382)\n");
        exit(0);
    }
    if (!str_contains($t, $oldToString)) {
        fwrite(STDERR, "collapsed StreamTrait __toString pattern not found in {$path}\n");
        exit(1);
    }
    $t = str_replace($oldToString, $newToString, $t);
    file_put_contents($path, $t);
    fwrite(STDOUT, "upgraded StreamTrait.php __toString rewind for AOT (#36382)\n");
    exit(0);
}

if (!str_contains($t, $old)) {
    fwrite(STDERR, "StreamTrait PHP_VERSION_ID if/else pattern not found in {$path}\n");
    exit(1);
}

// Drop unused imports after collapsing the else arm.
$t = str_replace($old, $new, $t);
$t = str_replace(
    "use Psr\\Http\\Message\\StreamInterface;\n"
    ."use Symfony\\Component\\Debug\\ErrorHandler as SymfonyLegacyErrorHandler;\n"
    ."use Symfony\\Component\\ErrorHandler\\ErrorHandler as SymfonyErrorHandler;\n\n",
    '',
    $t
);

file_put_contents($path, $t);
fwrite(STDOUT, "patched StreamTrait.php for AOT (#36382)\n");
