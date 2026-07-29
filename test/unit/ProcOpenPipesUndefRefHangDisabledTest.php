<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Guard #24481: stdlib/proc_open_pipes_undef_ref must stay out of VMTest discovery.
 *
 * Sibling of exec_null_valueerror — hangs indefinitely under bin/vm.php in the
 * compliance harness (shard 3/24 timed out at 2400s during baseline discovery).
 * BaseTest/shard-compliance only pick up *.phpt — keep as *.phpt.disabled until
 * the proc_open pipes path terminates.
 */
final class ProcOpenPipesUndefRefHangDisabledTest extends TestCase
{
    public function testHangCaseIsDisabledNotDiscovered(): void
    {
        $cases = dirname(__DIR__).'/compliance/cases/stdlib';
        $active = $cases.'/proc_open_pipes_undef_ref.phpt';
        $disabled = $cases.'/proc_open_pipes_undef_ref.phpt.disabled';

        self::assertFileDoesNotExist(
            $active,
            're-enabling as .phpt reintroduces an unbounded VMTest hang (#24481)'
        );
        self::assertFileExists($disabled);

        $discovered = [];
        foreach (new GlobIterator($cases.'/*.phpt') as $file) {
            $discovered[] = $file->getBasename();
        }
        self::assertNotContains(
            'proc_open_pipes_undef_ref.phpt',
            $discovered,
            'GlobIterator *.phpt must not discover the disabled hang case'
        );
    }
}
