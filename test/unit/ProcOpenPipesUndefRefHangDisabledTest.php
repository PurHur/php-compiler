<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Guard #24481: stdlib/proc_open_pipes_undef_ref must stay discoverable and terminate.
 *
 * Sibling of exec_null_valueerror — same SIGSTOP'd fork / stdout-FD hang class.
 */
final class ProcOpenPipesUndefRefHangDisabledTest extends TestCase
{
    public function testHangCaseIsActiveAndDiscovered(): void
    {
        $cases = dirname(__DIR__).'/compliance/cases/stdlib';
        $active = $cases.'/proc_open_pipes_undef_ref.phpt';
        $disabled = $cases.'/proc_open_pipes_undef_ref.phpt.disabled';

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
            'proc_open_pipes_undef_ref.phpt',
            $discovered,
            'GlobIterator *.phpt must discover the re-enabled hang case'
        );
    }
}
