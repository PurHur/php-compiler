<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Guard #24481: stdlib/exec_null_valueerror must stay out of VMTest discovery.
 *
 * The case hangs indefinitely under bin/vm.php when run via the compliance harness,
 * which made sharded VMTest baselines unbounded. BaseTest/shard-compliance only
 * pick up *.phpt — keep the fixture as *.phpt.disabled until the hang is fixed.
 */
final class ExecNullValueerrorHangDisabledTest extends TestCase
{
    public function testHangCaseIsDisabledNotDiscovered(): void
    {
        $cases = dirname(__DIR__).'/compliance/cases/stdlib';
        $active = $cases.'/exec_null_valueerror.phpt';
        $disabled = $cases.'/exec_null_valueerror.phpt.disabled';

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
            'exec_null_valueerror.phpt',
            $discovered,
            'GlobIterator *.phpt must not discover the disabled hang case'
        );
    }
}
