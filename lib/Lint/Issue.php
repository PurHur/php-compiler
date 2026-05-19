<?php

declare(strict_types=1);

namespace PHPCompiler\Lint;

use PHPCfg\Op;

/**
 * One unsupported CFG construct discovered during lint.
 */
final class Issue
{
    public string $file;
    public int $line;
    public string $kind;
    public string $message;
    public ?int $trackingIssue;

    public function __construct(
        string $file,
        int $line,
        string $kind,
        string $message,
        ?int $trackingIssue = null
    ) {
        $this->file = $file;
        $this->line = $line;
        $this->kind = $kind;
        $this->message = $message;
        $this->trackingIssue = $trackingIssue;
    }

    public static function fromOp(Op $op, string $compilerMessage): self
    {
        $kind = self::kindFromMessage($compilerMessage);
        $tracking = UnsupportedRegistry::trackingIssueForKind($kind);

        return new self(
            $op->getFile(),
            $op->getLine(),
            $kind,
            $compilerMessage,
            $tracking
        );
    }

    public static function kindFromMessage(string $message): string
    {
        if (preg_match('/^Unsupported expression: (.+)$/', $message, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^Unknown (?:Stmt|Op|BinaryOp|CastOp|UnaryOp|Terminal|Operand|Literal) Type: (.+)$/', $message, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^Unknown Literal Operand Type:/', $message)) {
            return 'Literal';
        }
        if (preg_match('/^Unsupported (?:class type|class body element): /', $message)) {
            return trim($message);
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'line' => $this->line,
            'kind' => $this->kind,
            'message' => $this->message,
            'issue' => $this->trackingIssue,
        ];
    }

    public function formatHuman(): string
    {
        $where = $this->line > 0 ? "line {$this->line}" : 'line ?';
        $suffix = null !== $this->trackingIssue ? " (see #{$this->trackingIssue})" : '';

        return "{$this->file}: {$where}: unsupported {$this->kind}{$suffix}";
    }
}
