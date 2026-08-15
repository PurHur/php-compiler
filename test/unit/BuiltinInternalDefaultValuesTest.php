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

    /** @covers issue #25361 */
    public function testSimilarTextPercentDefaultIsNull(): void
    {
        $info = ['name' => 'percent', 'type' => 'float', 'isOptional' => true];
        self::assertTrue(
            BuiltinInternalDefaultValues::isAvailable('similar_text', 2, $info, false)
        );
        $var = new Variable();
        self::assertTrue(
            BuiltinInternalDefaultValues::materialize($var, 'similar_text', 2, $info)
        );
        self::assertSame(Variable::TYPE_NULL, $var->type);
        self::assertSame('', BuiltinInternalArgInfo::stubParamTypeOverride('similar_text', 2));
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

    /** @covers issue #25070 */
    public function testRangeStepDefaultIsOne(): void
    {
        $info = ['name' => 'step', 'type' => 'int', 'isOptional' => true];
        self::assertTrue(
            BuiltinInternalDefaultValues::isAvailable('range', 2, $info, false)
        );
        $var = new Variable();
        self::assertTrue(
            BuiltinInternalDefaultValues::materialize($var, 'range', 2, $info)
        );
        self::assertSame(1, $var->toInt());
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

    /** @covers issue #25135 */
    public function testFputcsvReflectionDefaults(): void
    {
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'fputcsv',
            2,
            ['name' => 'separator', 'type' => 'string', 'isOptional' => true]
        ));
        self::assertSame(',', $dest->toString());

        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'fputcsv',
            3,
            ['name' => 'enclosure', 'type' => 'string', 'isOptional' => true]
        ));
        self::assertSame('"', $dest->toString());

        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'fputcsv',
            4,
            ['name' => 'escape', 'type' => 'string', 'isOptional' => true]
        ));
        self::assertSame('\\', $dest->toString());

        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'fputcsv',
            5,
            ['name' => 'eol', 'type' => 'string', 'isOptional' => true]
        ));
        self::assertSame("\n", $dest->toString());

        self::assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride('fputcsv', 5));

        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'splfileobject::fputcsv',
            4,
            ['name' => 'eol', 'type' => 'string', 'isOptional' => true]
        ));
        self::assertSame("\n", $dest->toString());
    }

    /** @covers issue #24814 */
    public function testFileGetContentsContextLengthDefaults(): void
    {
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'file_get_contents',
            2,
            ['name' => 'context', 'type' => '', 'isOptional' => true]
        ));
        self::assertSame(Variable::TYPE_NULL, $dest->type);

        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'file_get_contents',
            4,
            ['name' => 'length', 'type' => 'int', 'isOptional' => true]
        ));
        self::assertSame(Variable::TYPE_NULL, $dest->type);
        self::assertSame('?int', BuiltinInternalArgInfo::stubParamTypeOverride('file_get_contents', 4));

        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'fopen',
            3,
            ['name' => 'context', 'type' => '', 'isOptional' => true]
        ));
        self::assertSame(Variable::TYPE_NULL, $dest->type);

        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'rmdir',
            1,
            ['name' => 'context', 'type' => '', 'isOptional' => true]
        ));
        self::assertSame(Variable::TYPE_NULL, $dest->type);
    }

    /** @covers issue #25066 */
    public function testIteratorToArrayPreserveKeysDefaultTrue(): void
    {
        $info = ['name' => 'preserve_keys', 'type' => 'bool', 'isOptional' => true];
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('iterator_to_array', 1, $info, false));
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($dest, 'iterator_to_array', 1, $info));
        self::assertTrue($dest->toBool());
        self::assertSame(
            'Traversable|array',
            BuiltinInternalArgInfo::stubParamTypeOverride('iterator_to_array', 0)
        );
    }

    /** @covers issue #25174 */
    public function testTriggerErrorUserErrorErrorLevelDefaultEUserNotice(): void
    {
        $info = ['name' => 'error_level', 'type' => 'int', 'isOptional' => true];
        foreach (['trigger_error', 'user_error'] as $fn) {
            self::assertTrue(BuiltinInternalDefaultValues::isAvailable($fn, 1, $info, false), $fn);
            $dest = new Variable();
            self::assertTrue(BuiltinInternalDefaultValues::materialize($dest, $fn, 1, $info), $fn);
            self::assertSame(1024, $dest->toInt(), $fn);
        }
        self::assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride('user_error', 0));
        self::assertSame('int', BuiltinInternalArgInfo::stubParamTypeOverride('user_error', 1));
        self::assertSame(
            ['name' => 'message', 'type' => 'string', 'isOptional' => false],
            BuiltinInternalArgInfo::paramInfoForFunction('user_error', 0)
        );
        self::assertSame(
            ['name' => 'error_level', 'type' => 'int', 'isOptional' => true],
            BuiltinInternalArgInfo::paramInfoForFunction('user_error', 1)
        );
    }

    /** @covers issue #24969 */
    public function testPregFamilyReflectionLimitAndCountDefaults(): void
    {
        $limitInfo = ['name' => 'limit', 'type' => 'int', 'isOptional' => true];
        $countInfo = ['name' => 'count', 'type' => '', 'isOptional' => true];
        $flagsInfo = ['name' => 'flags', 'type' => 'int', 'isOptional' => true];

        foreach (['preg_replace', 'preg_filter'] as $fn) {
            self::assertTrue(BuiltinInternalDefaultValues::isAvailable($fn, 3, $limitInfo, false), $fn);
            $dest = new Variable();
            self::assertTrue(BuiltinInternalDefaultValues::materialize($dest, $fn, 3, $limitInfo), $fn);
            self::assertSame(-1, $dest->toInt(), $fn.' limit');

            self::assertTrue(BuiltinInternalDefaultValues::isAvailable($fn, 4, $countInfo, false), $fn);
            $dest = new Variable();
            self::assertTrue(BuiltinInternalDefaultValues::materialize($dest, $fn, 4, $countInfo), $fn);
            self::assertSame(Variable::TYPE_NULL, $dest->type, $fn.' count');
        }

        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('preg_replace_callback', 3, $limitInfo, false));
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($dest, 'preg_replace_callback', 3, $limitInfo));
        self::assertSame(-1, $dest->toInt());

        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('preg_replace_callback', 4, $countInfo, false));
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($dest, 'preg_replace_callback', 4, $countInfo));
        self::assertSame(Variable::TYPE_NULL, $dest->type);

        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('preg_replace_callback', 5, $flagsInfo, false));
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($dest, 'preg_replace_callback', 5, $flagsInfo));
        self::assertSame(0, $dest->toInt());

        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('preg_split', 2, $limitInfo, false));
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($dest, 'preg_split', 2, $limitInfo));
        self::assertSame(-1, $dest->toInt());
    }

    /** @covers issue #25044 */
    public function testStrSplitLengthDefaultIsOne(): void
    {
        $lengthInfo = ['name' => 'length', 'type' => 'int', 'isOptional' => true];
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('str_split', 1, $lengthInfo, false));
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($dest, 'str_split', 1, $lengthInfo));
        self::assertSame(1, $dest->toInt());
    }

    /** @covers issue #24811 */
    public function testImplodeJoinArrayDefaultIsNull(): void
    {
        $info = ['name' => 'array', 'type' => '?array', 'isOptional' => true];
        foreach (['implode', 'join'] as $fn) {
            self::assertTrue(BuiltinInternalDefaultValues::isAvailable($fn, 1, $info, false), $fn);
            $dest = new Variable();
            self::assertTrue(BuiltinInternalDefaultValues::materialize($dest, $fn, 1, $info), $fn);
            self::assertSame(Variable::TYPE_NULL, $dest->type, $fn);
            self::assertSame('array|string', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 0), $fn);
            self::assertSame('?array', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 1), $fn);
        }
    }

    /** @covers issue #25472 */
    public function testSubstrCountLengthAndPregQuoteDelimiterDefaultsNull(): void
    {
        $length = ['name' => 'length', 'type' => '?int', 'isOptional' => true];
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('substr_count', 3, $length, false));
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($dest, 'substr_count', 3, $length));
        self::assertSame(Variable::TYPE_NULL, $dest->type);

        $delimiter = ['name' => 'delimiter', 'type' => '?string', 'isOptional' => true];
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('preg_quote', 1, $delimiter, false));
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($dest, 'preg_quote', 1, $delimiter));
        self::assertSame(Variable::TYPE_NULL, $dest->type);
    }

    /** @covers issue #24813 */
    public function testStrGetcsvReflectionDefaults(): void
    {
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'str_getcsv',
            1,
            ['name' => 'separator', 'type' => 'string', 'isOptional' => true]
        ));
        self::assertSame(',', $dest->toString());

        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'str_getcsv',
            2,
            ['name' => 'enclosure', 'type' => 'string', 'isOptional' => true]
        ));
        self::assertSame('"', $dest->toString());

        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'str_getcsv',
            3,
            ['name' => 'escape', 'type' => 'string', 'isOptional' => true]
        ));
        self::assertSame('\\', $dest->toString());
    }

    /** @covers issue #24791 */
    public function testLevenshteinCostDefaultsAreOne(): void
    {
        $dest = new Variable();
        foreach ([2 => 'insertion_cost', 3 => 'replacement_cost', 4 => 'deletion_cost'] as $index => $name) {
            $info = ['name' => $name, 'type' => 'int', 'isOptional' => true];
            self::assertTrue(
                BuiltinInternalDefaultValues::isAvailable('levenshtein', $index, $info, false),
                $name
            );
            self::assertTrue(
                BuiltinInternalDefaultValues::materialize($dest, 'levenshtein', $index, $info),
                $name
            );
            self::assertSame(1, $dest->toInt(), $name);
        }
    }

    /** @covers issue #25013 */
    public function testClassExistsAutoloadDefaultIsTrue(): void
    {
        $info = ['name' => 'autoload', 'type' => 'bool', 'isOptional' => true];
        self::assertTrue(
            BuiltinInternalDefaultValues::isAvailable('class_exists', 1, $info, false)
        );
        $dest = new Variable();
        self::assertTrue(
            BuiltinInternalDefaultValues::materialize($dest, 'class_exists', 1, $info)
        );
        self::assertSame(Variable::TYPE_BOOLEAN, $dest->type);
        self::assertTrue($dest->toBool());
    }

    /** @covers issue #25388 */
    public function testClassAliasAutoloadDefaultIsTrue(): void
    {
        $info = ['name' => 'autoload', 'type' => 'bool', 'isOptional' => true];
        self::assertTrue(
            BuiltinInternalDefaultValues::isAvailable('class_alias', 2, $info, false)
        );
        $dest = new Variable();
        self::assertTrue(
            BuiltinInternalDefaultValues::materialize($dest, 'class_alias', 2, $info)
        );
        self::assertSame(Variable::TYPE_BOOLEAN, $dest->type);
        self::assertTrue($dest->toBool());
    }

    /** @covers issue #25390 */
    public function testSplAutoloadRegisterThrowDefaultIsTrue(): void
    {
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'spl_autoload_register',
            0,
            ['name' => 'callback', 'type' => '?callable', 'isOptional' => true]
        ));
        self::assertSame(Variable::TYPE_NULL, $dest->type);
        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'spl_autoload_register',
            1,
            ['name' => 'throw', 'type' => 'bool', 'isOptional' => true]
        ));
        self::assertTrue($dest->toBool());
        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'spl_autoload_register',
            2,
            ['name' => 'prepend', 'type' => 'bool', 'isOptional' => true]
        ));
        self::assertFalse($dest->toBool());
    }

    /** @covers issue #25392 */
    public function testDateCreateDatetimeAndTimezoneDefaults(): void
    {
        $dest = new Variable();
        foreach (['date_create', 'date_create_immutable'] as $fn) {
            self::assertTrue(BuiltinInternalDefaultValues::materialize(
                $dest,
                $fn,
                0,
                ['name' => 'datetime', 'type' => 'string', 'isOptional' => true]
            ), $fn);
            self::assertSame('now', $dest->toString(), $fn);
            self::assertTrue(BuiltinInternalDefaultValues::materialize(
                $dest,
                $fn,
                1,
                ['name' => 'timezone', 'type' => '?DateTimeZone', 'isOptional' => true]
            ), $fn);
            self::assertSame(Variable::TYPE_NULL, $dest->type, $fn);
        }
    }

    /** @covers issue #25400 */
    public function testDateTimeSetTimeSecondAndMicrosecondDefaultsAreZero(): void
    {
        $dest = new Variable();
        foreach (['datetime::settime', 'datetimeimmutable::settime'] as $callable) {
            foreach ([2 => 'second', 3 => 'microsecond'] as $index => $name) {
                $info = ['name' => $name, 'type' => 'int', 'isOptional' => true];
                self::assertTrue(
                    BuiltinInternalDefaultValues::isAvailable($callable, $index, $info, false),
                    "$callable::$name"
                );
                self::assertTrue(
                    BuiltinInternalDefaultValues::materialize($dest, $callable, $index, $info),
                    "$callable::$name"
                );
                self::assertSame(0, $dest->toInt(), "$callable::$name");
            }
        }
    }

    /** php-src ext/curl/curl.stub.php — ?string $url = null (#26186). */
    public function testCurlInitUrlDefaultIsNull(): void
    {
        $info = ['name' => 'url', 'type' => '?string', 'isOptional' => true];
        self::assertTrue(BuiltinInternalDefaultValues::isAvailable('curl_init', 0, $info, false));
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize($dest, 'curl_init', 0, $info));
        self::assertSame(Variable::TYPE_NULL, $dest->type);
    }


    /** @covers issue #26184 */
    public function testFilterVarArrayAndFilterInputReflectionDefaults(): void
    {
        $dest = new Variable();
        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'filter_var_array',
            1,
            ['name' => 'options', 'type' => 'array|int', 'isOptional' => true]
        ));
        self::assertSame(516, $dest->toInt());
        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'filter_var_array',
            2,
            ['name' => 'add_empty', 'type' => 'bool', 'isOptional' => true]
        ));
        self::assertTrue($dest->toBool());
        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'filter_input',
            2,
            ['name' => 'filter', 'type' => 'int', 'isOptional' => true]
        ));
        self::assertSame(516, $dest->toInt());
        self::assertTrue(BuiltinInternalDefaultValues::materialize(
            $dest,
            'filter_input',
            3,
            ['name' => 'options', 'type' => 'array|int', 'isOptional' => true]
        ));
        self::assertSame(0, $dest->toInt());
    }
}
