<?php

declare(strict_types=1);

/**
 * Harden Nyholm MessageTrait::getBody for AOT Composer graphs (#36382).
 *
 * Reading `$this->bodyStream` a second time under IncludeHelper AOT SEGVs (property
 * slot / multi-candidate dispatch). Cache the StreamInterface by spl_object_id so
 * write() + later (string) getBody() share one instance — Zend-equivalent for the
 * deferred-null body path.
 *
 * php-src: Zend/zend_inheritance.c zend_do_traits_property_binding;
 * Zend/zend_objects_API.c spl_object_id.
 */
$path = $argv[1] ?? '';
if ('' === $path || !is_file($path)) {
    fwrite(STDERR, "usage: php script/composer/patch-nyholm-message-trait-getbody-36382.php <MessageTrait.php>\n");
    exit(1);
}
$t = file_get_contents($path);
if (false === $t) {
    fwrite(STDERR, "cannot read {$path}\n");
    exit(1);
}
if (str_contains($t, 'AOT (#36382): getBody spl_object_id cache')) {
    fwrite(STDOUT, "MessageTrait.php getBody already patched (#36382)\n");
    exit(0);
}

$old = <<<'PHP'
    public function getBody(): StreamInterface
    {
        if (null === $this->stream) {
            $this->stream = Stream::create('');
        }

        return $this->stream;
    }
PHP;
$new = <<<'PHP'
    public function getBody(): StreamInterface
    {
        // AOT (#36382): getBody spl_object_id cache — second `$this->(body)Stream` read SEGVs
        // under IncludeHelper; cache keeps write() + echo (string)getBody() on one stream.
        static $aotBodyCache36382 = [];
        $oid = \spl_object_id($this);
        if (isset($aotBodyCache36382[$oid]) && $aotBodyCache36382[$oid] instanceof StreamInterface) {
            return $aotBodyCache36382[$oid];
        }
        $stream = Stream::create('');
        $this->stream = $stream;
        return $aotBodyCache36382[$oid] = $stream;
    }
PHP;
if (!str_contains($t, $old)) {
    // Already bodyStream-renamed or prior instanceof patch
    $old2 = <<<'PHP'
    public function getBody(): StreamInterface
    {
        // AOT (#36382): getBody instanceof StreamInterface local — trait `$stream` can
        // alias Response `$statusCode` under IncludeHelper; return a real stream.
        $stream = $this->stream;
        if (!($stream instanceof StreamInterface)) {
            $stream = Stream::create('');
            $this->stream = $stream;
        }

        return $stream;
    }
PHP;
    $old3 = <<<'PHP'
    public function getBody(): StreamInterface
    {
        // AOT (#36382): getBody instanceof StreamInterface local — trait `$stream` can
        // alias Response `$statusCode` under IncludeHelper; return a real stream.
        $stream = $this->bodyStream;
        if (!($stream instanceof StreamInterface)) {
            $stream = Stream::create('');
            $this->bodyStream = $stream;
        }

        return $stream;
    }
PHP;
    if (str_contains($t, $old2)) {
        $t = str_replace($old2, $new, $t);
    } elseif (str_contains($t, $old3)) {
        $newBody = str_replace('$this->stream', '$this->bodyStream', $new);
        $t = str_replace($old3, $newBody, $t);
    } elseif (str_contains($t, 'if (null === $this->bodyStream)')) {
        $old4 = <<<'PHP'
    public function getBody(): StreamInterface
    {
        if (null === $this->bodyStream) {
            $this->bodyStream = Stream::create('');
        }

        return $this->bodyStream;
    }
PHP;
        $newBody = str_replace('$this->stream', '$this->bodyStream', $new);
        if (!str_contains($t, $old4)) {
            fwrite(STDERR, "getBody pattern not found in {$path}\n");
            exit(1);
        }
        $t = str_replace($old4, $newBody, $t);
    } else {
        fwrite(STDERR, "getBody pattern not found in {$path}\n");
        exit(1);
    }
} else {
    $t = str_replace($old, $new, $t);
}
if (false === file_put_contents($path, $t)) {
    fwrite(STDERR, "cannot write {$path}\n");
    exit(1);
}
fwrite(STDOUT, "patched MessageTrait::getBody spl_object_id cache for AOT (#36382)\n");
