<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\BuiltinInternalArgInfo;
use PHPCompiler\BuiltinInternalDefaultValues;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** @covers BuiltinInternalDefaultValues */
final class BuiltinInternalDefaultValuesTest extends TestCase
{
    public function testArrayObjectConstructDefaults(): void
    {
        $info = ['name' => 'array', 'type' => '', 'isOptional' => true];
        self::assertTrue(
            BuiltinInternalDefaultValues::isAvailable('arrayobject::__construct', 0, $info, false)
        );
        $var = new Variable();
        self::assertTrue(
            BuiltinInternalDefaultValues::materialize($var, 'arrayobject::__construct', 0, $info)
        );
        self::assertSame(Variable::TYPE_ARRAY, $var->type);
        self::assertSame(0, $var->toArray()->getNumElements());

        $flags = ['name' => 'flags', 'type' => 'int', 'isOptional' => true];
        $var = new Variable();
        self::assertTrue(
            BuiltinInternalDefaultValues::materialize($var, 'arrayobject::__construct', 1, $flags)
        );
        self::assertSame(0, $var->toInt());

        $iter = ['name' => 'iterator_class', 'type' => 'string', 'isOptional' => true];
        $var = new Variable();
        self::assertTrue(
            BuiltinInternalDefaultValues::materialize($var, 'arrayobject::__construct', 2, $iter)
        );
        self::assertSame('ArrayIterator', $var->toString());
    }

    public function testOptionalWithoutReflectionDefaultIsUnavailable(): void
    {
        $info = ['name' => 'userdata', 'type' => '', 'isOptional' => true];
        self::assertFalse(
            BuiltinInternalDefaultValues::isAvailable('array_walk', 2, $info, false)
        );
    }

    public function testStrReplaceCountDefaultIsNull(): void
    {
        $info = ['name' => 'count', 'type' => 'int', 'isOptional' => true];
        self::assertTrue(
            BuiltinInternalDefaultValues::isAvailable('str_replace', 3, $info, false)
        );
        $var = new Variable();
        self::assertTrue(
            BuiltinInternalDefaultValues::materialize($var, 'str_replace', 3, $info)
        );
        self::assertSame(Variable::TYPE_NULL, $var->type);

        self::assertTrue(
            BuiltinInternalDefaultValues::isAvailable('str_ireplace', 3, $info, false)
        );
        $var = new Variable();
        self::assertTrue(
            BuiltinInternalDefaultValues::materialize($var, 'str_ireplace', 3, $info)
        );
        self::assertSame(Variable::TYPE_NULL, $var->type);

        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('str_replace', 3));
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('str_ireplace', 3));

        // #23587 — ext/pcre/php_pcre.stub.php unions + untyped &$count
        self::assertSame('array|string', BuiltinInternalArgInfo::stubParamTypeOverride('preg_replace', 0));
        self::assertSame('array|string', BuiltinInternalArgInfo::stubParamTypeOverride('preg_filter', 1));
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('preg_replace', 4));
        self::assertSame('callable', BuiltinInternalArgInfo::stubParamTypeOverride('preg_replace_callback', 1));
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('preg_replace_callback', 4));
    }

    /** @covers issue #24896 */
    public function testUnpackOffsetDefaultIsZero(): void
    {
        $info = ['name' => 'offset', 'type' => '', 'isOptional' => true];
        self::assertTrue(
            BuiltinInternalDefaultValues::isAvailable('unpack', 2, $info, false)
        );
        $var = new Variable();
        self::assertTrue(
            BuiltinInternalDefaultValues::materialize($var, 'unpack', 2, $info)
        );
        self::assertSame(0, $var->toInt());
    }

    /** @covers issue #24971 */
    public function testPathQueryClusterDefaults(): void
    {
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'dirname',
            1,
            ['name' => 'levels', 'type' => 'int', 'isOptional' => true]
        ));
        self::assertSame(1, $dest->toInt());

        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'chunk_split',
            1,
            ['name' => 'length', 'type' => 'int', 'isOptional' => true]
        ));
        self::assertSame(76, $dest->toInt());

        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'getimagesize',
            1,
            ['name' => 'image_info', 'type' => 'array', 'isOptional' => true]
        ));
        self::assertSame(Variable::TYPE_NULL, $dest->type);

        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'umask',
            0,
            ['name' => 'mask', 'type' => 'int', 'isOptional' => true]
        ));
        self::assertSame(Variable::TYPE_NULL, $dest->type);
    }

    /** @covers issue #25069 */
    public function testStreamContextCreateOptionsParamsDefaultNull(): void
    {
        $info = ['name' => 'options', 'type' => 'array', 'isOptional' => true];
        self::assertTrue(
            BuiltinInternalDefaultValues::isAvailable('stream_context_create', 0, $info, false)
        );
        $var = new Variable();
        self::assertTrue(
            BuiltinInternalDefaultValues::materialize($var, 'stream_context_create', 0, $info)
        );
        self::assertSame(Variable::TYPE_NULL, $var->type);

        $params = ['name' => 'params', 'type' => 'array', 'isOptional' => true];
        self::assertTrue(
            BuiltinInternalDefaultValues::materialize($var, 'stream_context_create', 1, $params)
        );
        self::assertSame(Variable::TYPE_NULL, $var->type);

        self::assertSame('?array', BuiltinInternalArgInfo::stubParamTypeOverride('stream_context_create', 0));
        self::assertSame('?array', BuiltinInternalArgInfo::stubParamTypeOverride('stream_context_create', 1));
    }

    /** @covers issue #24846 */
    public function testFwriteFgetsLengthDefaultNull(): void
    {
        $info = ['name' => 'length', 'type' => 'int', 'isOptional' => true];
        $var = new Variable();
        self::assertTrue(
            BuiltinInternalDefaultValues::materialize($var, 'fwrite', 2, $info)
        );
        self::assertSame(Variable::TYPE_NULL, $var->type);
        self::assertTrue(
            BuiltinInternalDefaultValues::materialize($var, 'fgets', 1, $info)
        );
        self::assertSame(Variable::TYPE_NULL, $var->type);
        self::assertSame('?int', BuiltinInternalArgInfo::stubParamTypeOverride('fwrite', 2));
        self::assertSame('?int', BuiltinInternalArgInfo::stubParamTypeOverride('fgets', 1));
    }

    /** @covers issue #24826 */
    public function testFgetcsvReflectionDefaults(): void
    {
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'fgetcsv',
            1,
            ['name' => 'length', 'type' => 'int', 'isOptional' => true]
        ));
        self::assertSame(Variable::TYPE_NULL, $dest->type);

        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'fgetcsv',
            2,
            ['name' => 'separator', 'type' => 'string', 'isOptional' => true]
        ));
        self::assertSame(',', $dest->toString());

        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'fgetcsv',
            3,
            ['name' => 'enclosure', 'type' => 'string', 'isOptional' => true]
        ));
        self::assertSame('"', $dest->toString());

        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'fgetcsv',
            4,
            ['name' => 'escape', 'type' => 'string', 'isOptional' => true]
        ));
        self::assertSame('\\', $dest->toString());

        self::assertSame('?int', BuiltinInternalArgInfo::stubParamTypeOverride('fgetcsv', 1));
    }

}
