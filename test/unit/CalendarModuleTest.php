<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\calendar\CalendarConstants;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * calendar module skeleton registration (issue #7133).
 *
 * @group calendar_module_skeleton
 */
final class CalendarModuleTest extends TestCase
{
    public function test_calendar_module_skeleton_registration(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        self::assertTrue(VmReflection::functionExists($ctx, 'cal_days_in_month'));
        self::assertTrue(VmReflection::functionExists($ctx, 'cal_info'));
        self::assertTrue(VmReflection::functionExists($ctx, 'cal_from_jd'));
        self::assertTrue(VmReflection::functionExists($ctx, 'gregoriantojd'));
        self::assertTrue(VmReflection::functionExists($ctx, 'easter_date'));
        self::assertTrue(VmReflection::functionExists($ctx, 'easter_days'));
        self::assertTrue(VmReflection::functionExists($ctx, 'jdmonthname'));
        self::assertTrue(VmReflection::functionExists($ctx, 'jddayofweek'));

        $code = <<<'PHP'
<?php
echo (int) defined('CAL_GREGORIAN');
echo (int) function_exists('cal_days_in_month');
echo (int) function_exists('gregoriantojd');
echo (int) function_exists('easter_date');
echo CAL_GREGORIAN;
echo CAL_JULIAN;
echo CAL_NUM_CALS;
PHP;
        $block = $runtime->parseAndCompile($code, 'calendar_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            '1111014',
            ob_get_clean()
        );
    }
}
