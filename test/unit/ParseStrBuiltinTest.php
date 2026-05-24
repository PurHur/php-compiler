<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\parse_str;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** Issue #1367: parse_str() VM builtin. */
final class ParseStrBuiltinTest extends TestCase
{
    public function testParseStrPopulatesResultArray(): void
    {
        $runtime = new Runtime();
        $encoded = new VMVariable();
        $encoded->string('a=1&user%5Bname%5D=Ada');
        $result = new VMVariable();
        $result->newArray();

        $builtin = new parse_str();
        $callFrame = $builtin->getFrame($runtime->vmContext);
        $callFrame->calledArgs = [$encoded, $result];
        $callFrame->returnVar = new VMVariable();
        $builtin->execute($callFrame);

        $table = $result->resolveIndirect()->toArray();
        $this->assertSame('1', $table->find('a')->resolveIndirect()->toString());
        $user = $table->find('user')->resolveIndirect()->toArray();
        $this->assertSame('Ada', $user->find('name')->resolveIndirect()->toString());
    }
}
