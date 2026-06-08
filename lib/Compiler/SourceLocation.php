<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PhpParser\Comment\Doc;

/**
 * Compile-time docblock + declaration site for reflection (#7358).
 *
 * php-src: ext/reflection/php_reflection.c — reflection_get_doc_comment / reflection_get_filename
 */
final class SourceLocation
{
    public function __construct(
        public readonly ?string $docComment = null,
        public readonly int $startLine = 0,
        public readonly int $endLine = 0,
        public readonly string $filename = '',
    ) {
    }

    public static function fromOp(Op $op): self
    {
        $doc = null;
        if ($op->hasAttribute('doccomment')) {
            $raw = $op->getAttribute('doccomment');
            if ($raw instanceof Doc) {
                $doc = $raw->getText();
            }
        }
        $start = max(0, $op->getLine());
        $end = max(0, (int) $op->getAttribute('endLine', 0));
        $file = (string) $op->getAttribute('filename', '');

        return new self($doc, $start, $end, $file);
    }
}
