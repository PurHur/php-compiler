<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * HashTable rehash must compact tombstones so unset+rewrite on a full table
 * does not clobber sibling keys via MaskedArray wrap (#36380).
 *
 * php-src: Zend/zend_hash.c zend_hash_rehash
 */
final class HashTableRehashCompact36380Test extends TestCase
{
    private const CODE = <<<'PHP'
function mutate(array $B): array {
    unset($B['li']);
    $B['indent'] = 0;
    $B['li'] = ['x' => 1];
    $B['element']['elements'][] = &$B['li'];
    return $B;
}
$B = ['indent' => 0, 'a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5];
$B['li'] = ['v' => 1];
$B['element'] = ['elements' => []];
$B['element']['elements'][] = &$B['li'];
$B = mutate($B);
$keys = array_keys($B);
echo 'indent=', var_export($B['indent'] ?? 'MISSING', true), "\n";
echo 'dup=', (count($keys) !== count(array_unique($keys))) ? '1' : '0', "\n";
echo 'has_li=', array_key_exists('li', $B) ? '1' : '0', "\n";
PHP;

    private const EXPECT = "indent=0\ndup=0\nhas_li=1\n";

    public function testVmMatchesZend(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php'));
    }

    private function runBin(string $relBin): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_ht_rehash_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n" . self::CODE);
        $run = proc_open(
            ['php', $repo . '/' . $relBin, $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $_ENV
        );
        $this->assertIsResource($run);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($run), trim((string) $err));
        @unlink($tmp);

        return (string) $out;
    }
}
