<?php

declare(strict_types=1);

/**
 * Rewrite Nyholm Stream.php for thin AOT Composer graphs (#36382).
 *
 * set_error_handler(static closure) + anonymous stream-wrapper class currently fail
 * LLVM module verify (closure return __string__* vs __value__).
 */
$stream = $argv[1] ?? '';
if ('' === $stream || !is_file($stream)) {
    fwrite(STDERR, "usage: php script/patch-nyholm-stream-36382.php <Stream.php>\n");
    exit(1);
}
$t = file_get_contents($stream);
if (false === $t) {
    fwrite(STDERR, "cannot read {$stream}\n");
    exit(1);
}
if (str_contains($t, 'AOT (#36382): avoid set_error_handler(static closure)')) {
    fwrite(STDOUT, "Stream.php already patched (#36382)\n");
    exit(0);
}

$oldGc = <<<'PHP'
    public function getContents(): string
    {
        if (!isset($this->stream)) {
            throw new \RuntimeException('Stream is detached');
        }

        $exception = null;

        \set_error_handler(static function ($type, $message) use (&$exception) {
            throw $exception = new \RuntimeException('Unable to read stream contents: ' . $message);
        });

        try {
            return \stream_get_contents($this->stream);
        } catch (\Throwable $e) {
            throw $e === $exception ? $e : new \RuntimeException('Unable to read stream contents: ' . $e->getMessage(), 0, $e);
        } finally {
            \restore_error_handler();
        }
    }
PHP;
$newGc = <<<'PHP'
    public function getContents(): string
    {
        // AOT (#36382): avoid set_error_handler(static closure) IR return-type mismatch in Composer graphs.
        if (!isset($this->stream)) {
            throw new \RuntimeException('Stream is detached');
        }
        $result = @\stream_get_contents($this->stream);
        if (false === $result) {
            throw new \RuntimeException('Unable to read stream contents');
        }

        return $result;
    }
PHP;
if (!str_contains($t, $oldGc)) {
    fwrite(STDERR, "getContents pattern not found in {$stream}\n");
    exit(1);
}
$t = str_replace($oldGc, $newGc, $t);

$start = strpos($t, '    private static function openZvalStream(string $body)');
$endMarker = "        return \$stream;\n    }\n";
$end = false === $start ? false : strpos($t, $endMarker, $start);
if (false === $start || false === $end) {
    fwrite(STDERR, "openZvalStream pattern not found in {$stream}\n");
    exit(1);
}
$end += strlen($endMarker);
$newOz = <<<'PHP'
    private static function openZvalStream(string $body)
    {
        // AOT (#36382): skip anonymous stream wrapper class (closure IR verify failure in Composer AOT).
        $resource = \fopen('php://temp', 'r+');
        \fwrite($resource, $body);
        \fseek($resource, 0);

        return $resource;
    }

PHP;
$t = substr($t, 0, $start).$newOz.substr($t, $end);
if (false === file_put_contents($stream, $t)) {
    fwrite(STDERR, "cannot write {$stream}\n");
    exit(1);
}
fwrite(STDOUT, "patched Stream.php for AOT (#36382)\n");
