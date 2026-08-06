<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * imap_mail_compose() — build MIME message text (php-src ext/imap/php_imap.c; #27765).
 */
final class imap_mail_compose extends Internal
{
    public function __construct()
    {
        parent::__construct('imap_mail_compose');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'imap_mail_compose', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $envelopeVar = $frame->calledArgs[0]->resolveIndirect();
        $bodiesVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $envelopeVar->type) {
            throw new \TypeError(\sprintf(
                'imap_mail_compose(): Argument #1 ($envelope) must be of type array, %s given',
                EnumCaseSupport::typeNameForVariable($envelopeVar)
            ));
        }
        if (Variable::TYPE_ARRAY !== $bodiesVar->type) {
            throw new \TypeError(\sprintf(
                'imap_mail_compose(): Argument #2 ($bodies) must be of type array, %s given',
                EnumCaseSupport::typeNameForVariable($bodiesVar)
            ));
        }
        $envelope = VmImapMailCompose::variableToPhpArray($envelopeVar);
        $bodies = VmImapMailCompose::variableToPhpArray($bodiesVar);
        $msg = VmImapMailCompose::compose($envelope, $bodies);
        if (false === $msg) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($msg);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        unset($context, $args);
        throw new \LogicException('imap_mail_compose() is not implemented for JIT in this compiler build (issue #27765)');
    }
}
