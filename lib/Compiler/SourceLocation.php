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

    /**
     * Reflection getters / __toString — unwrap wrapEvalCode line shift (#26032).
     *
     * Stored lines stay wrap-shifted so nested eval __FILE__ / evalCallSiteLine stay correct.
     */
    public function forReflection(): self
    {
        if ('' === $this->filename
            || !\PHPCompiler\ext\standard\VmEval::isEvalScriptPath($this->filename)
        ) {
            return $this;
        }
        $start = $this->startLine > 0
            ? \PHPCompiler\ext\standard\VmEval::unwrapEvalLine($this->startLine)
            : 0;
        $end = $this->endLine > 0
            ? \PHPCompiler\ext\standard\VmEval::unwrapEvalLine($this->endLine)
            : 0;
        if ($start === $this->startLine && $end === $this->endLine) {
            return $this;
        }

        return new self($this->docComment, $start, $end, $this->filename);
    }

    public static function fromOp(Op $op): self
    {
        $doc = self::docCommentFromOp($op);
        $start = max(0, $op->getLine());
        $end = max(0, (int) $op->getAttribute('endLine', 0));
        $file = (string) $op->getAttribute('filename', '');

        return new self($doc, $start, $end, $file);
    }

    /**
     * Prefer dedicated doccomment attrs; fall back to the last Doc in comments
     * (class constants often only carry `comments`, #22419).
     */
    private static function docCommentFromOp(Op $op): ?string
    {
        foreach (['doccomment', 'docComment'] as $key) {
            if (!$op->hasAttribute($key)) {
                continue;
            }
            $text = self::commentText($op->getAttribute($key));
            if (null !== $text) {
                return $text;
            }
        }
        if ($op->hasAttribute('comments')) {
            $comments = $op->getAttribute('comments');
            if (\is_array($comments)) {
                for ($i = \count($comments) - 1; $i >= 0; --$i) {
                    $text = self::commentText($comments[$i]);
                    if (null !== $text && str_starts_with(ltrim($text), '/**')) {
                        return $text;
                    }
                }
            }
        }

        return null;
    }

    private static function commentText(mixed $comment): ?string
    {
        if ($comment instanceof Doc) {
            return $comment->getText();
        }
        if (\is_object($comment) && method_exists($comment, 'getText')) {
            $text = $comment->getText();

            return \is_string($text) ? $text : null;
        }
        if (\is_string($comment) && '' !== $comment) {
            return $comment;
        }

        return null;
    }
}
