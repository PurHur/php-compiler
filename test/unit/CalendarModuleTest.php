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
        self::assertTrue(VmReflection::functionExists($ctx, 'gregoriantojd'));

        $code = <<<'PHP'
<?php
echo (int) defined('CAL_GREGORIAN');
echo (int) function_exists('cal_days_in_month');
echo (int) function_exists('gregoriantojd');
echo CAL_GREGORIAN;
echo CAL_JULIAN;
echo CAL_NUM_CALS;
PHP;
        $block = $runtime->parseAndCompile($code, 'calendar_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            '1110'.CalendarConstants::CAL_JULIAN.CalendarConstants::CAL_NUM_CALS,
            ob_get_clean()
        );
    }
}
