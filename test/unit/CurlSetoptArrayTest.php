<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\curl\CurlConstants;
use PHPCompiler\ext\curl\curl_setopt_array;
use PHPCompiler\ext\curl\VmCurlEasy;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** @group curl_setopt_array */
final class CurlSetoptArrayTest extends TestCase
{
    public function testSetoptArrayAppliesUrlAndReturnTransfer(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        VmCurlEasy::registerClass($ctx);
        $easyVar = VmCurlEasy::init(null, $ctx);
        $easy = $easyVar->toObject();

        $options = new VMVariable();
        $options->newArray();
        $arr = $options->toArray();
        $urlVal = new VMVariable();
        $urlVal->string('http://example.test/');
        $arr->addIndex(CurlConstants::CURLOPT_URL, $urlVal);
        $rtVal = new VMVariable();
        $rtVal->bool(true);
        $arr->addIndex(CurlConstants::CURLOPT_RETURNTRANSFER, $rtVal);

        $frame = (new curl_setopt_array())->getFrame($ctx);
        $handle = new VMVariable();
        $handle->object($easy);
        $frame->calledArgs = [$handle, $options];
        $frame->returnVar = new VMVariable();
        (new curl_setopt_array())->execute($frame);

        self::assertTrue($frame->returnVar->toBool());
    }

    public function testNonArraySecondArgumentTypeError(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        VmCurlEasy::registerClass($ctx);
        $easyVar = VmCurlEasy::init(null, $ctx);

        $frame = (new curl_setopt_array())->getFrame($ctx);
        $handle = new VMVariable();
        $handle->object($easyVar->toObject());
        $bad = new VMVariable();
        $bad->string('x');
        $frame->calledArgs = [$handle, $bad];
        $frame->returnVar = new VMVariable();

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('must be of type array');

        (new curl_setopt_array())->execute($frame);
    }

    public function testInvalidStringOptionKeyValueError(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        VmCurlEasy::registerClass($ctx);
        $easyVar = VmCurlEasy::init(null, $ctx);

        $options = new VMVariable();
        $options->newArray();
        $val = new VMVariable();
        $val->int(1);
        $options->toArray()->add('not-an-option', $val);

        $frame = (new curl_setopt_array())->getFrame($ctx);
        $handle = new VMVariable();
        $handle->object($easyVar->toObject());
        $frame->calledArgs = [$handle, $options];
        $frame->returnVar = new VMVariable();

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('contains an invalid cURL option');

        (new curl_setopt_array())->execute($frame);
    }
}
