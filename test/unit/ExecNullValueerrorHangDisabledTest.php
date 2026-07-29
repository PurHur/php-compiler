<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Guard #24481: stdlib/exec_null_valueerror must stay discoverable and terminate.
 *
 * Root cause was FFI proc_open leaving a SIGSTOP'd fork child holding the VM
 * subprocess stdout FD, so BaseTest::runVmSubprocess blocked forever on EOF.
 * Fixed by resuming the child before proc_open returns + TypeError on null command.
 */
final class ExecNullValueerrorHangDisabledTest extends TestCase
{
    public function testHangCaseIsActiveAndDiscovered(): void
    {
        $cases = dirname(__DIR__).'/compliance/cases/stdlib';
        $active = $cases.'/exec_null_valueerror.phpt';
        $disabled = $cases.'/exec_null_valueerror.phpt.disabled';

        self::assertFileExists($active);
        self::assertFileDoesNotExist(
            $disabled,
            'hang case must stay enabled as .phpt after #24481 fix'
        );

        $discovered = [];
        foreach (new GlobIterator($cases.'/*.phpt') as $file) {
            $discovered[] = $file->getBasename();
        }
        self::assertContains(
            'exec_null_valueerror.phpt',
            $discovered,
            'GlobIterator *.phpt must discover the re-enabled hang case'
        );
    }
}
