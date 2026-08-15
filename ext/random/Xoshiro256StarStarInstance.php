<?php

declare(strict_types=1);

namespace PHPCompiler\ext\random;

use PHPCompiler\ext\standard\VmString;

/** Random\\Engine\\Xoshiro256StarStar (php-src engine_xoshiro256starstar.c; #11550). */
final class Xoshiro256StarStarInstance
{
    /** @var list<RandomU64> */
    private array $state;

    public function __construct()
    {
        $this->state = array_fill(0, 4, RandomU64::from32(0));
    }

    public function seedFromInt(int $seed): void
    {
        $s = RandomU64::from32($seed & 0xFFFFFFFF);
        $this->state = [self::splitmix64($s), self::splitmix64($s), self::splitmix64($s), self::splitmix64($s)];
    }

    public function seedFromBytes(string $bytes): void
    {
        if (32 !== \strlen($bytes)) {
            throw new \ValueError('Random\\Engine\\Xoshiro256StarStar::__construct(): Argument #1 ($seed) must be a 32 byte (256 bit) string');
        }
        $parts = \unpack('P4', $bytes);
        $this->state = [];
        for ($i = 1; $i <= 4; ++$i) {
            $word = (int) ($parts[$i] ?? 0);
            // unpack('P') is a full uint64 bit-pattern; fromParts(0, $word) truncated to 32 bits (#31053).
            $this->state[] = RandomU64::fromParts(($word >> 32) & 0xFFFFFFFF, $word & 0xFFFFFFFF);
        }
    }

    public function seedRandom(): void
    {
        $this->seedFromBytes(VmString::randomBytes(32));
    }

    public function generate(): string
    {
        return $this->generateState()->toBytes();
    }

    public function jump(): void
    {
        $this->jumpWithTable([
            RandomU64::fromHex64(0x0180EC6, 0xD33D5560),
            RandomU64::fromHex64(0x0865B889, 0xB587ABF8),
            RandomU64::fromHex64(0x08F1BBCD, 0xCBC32C98),
            RandomU64::fromHex64(0x048B0BAB, 0x8B7E1975),
        ]);
    }

    public function jumpLong(): void
    {
        $this->jumpWithTable([
            RandomU64::fromHex64(0x076E15D3, 0xFEFAFBBD),
            RandomU64::fromHex64(0x0C500D4D, 0x48169083),
            RandomU64::fromHex64(0x09E3779B, 0x185EB0CA),
            RandomU64::fromHex64(0x0CDE6B33, 0xFE90D63),
        ]);
    }

    /** @param list<RandomU64> $jmp */
    private function jumpWithTable(array $jmp): void
    {
        $s = [RandomU64::from32(0), RandomU64::from32(0), RandomU64::from32(0), RandomU64::from32(0)];
        for ($i = 0; $i < 4; ++$i) {
            for ($j = 0; $j < 64; ++$j) {
                if ($jmp[$i]->lowBitSet($j)) {
                    for ($k = 0; $k < 4; ++$k) {
                        $s[$k] = RandomU64::xor($s[$k], $this->state[$k]);
                    }
                }
                $this->generateState();
            }
        }
        $this->state = $s;
    }

    private function generateState(): RandomU64
    {
        $r = RandomU64::rotl(RandomU64::mul32($this->state[1], 5), 7);
        $r = RandomU64::mul32($r, 9);
        $t = $this->state[1]->shiftLeft(17);
        $this->state[2] = RandomU64::xor($this->state[2], $this->state[0]);
        $this->state[3] = RandomU64::xor($this->state[3], $this->state[1]);
        $this->state[1] = RandomU64::xor($this->state[1], $this->state[2]);
        $this->state[0] = RandomU64::xor($this->state[0], $this->state[3]);
        $this->state[2] = RandomU64::xor($this->state[2], $t);
        $this->state[3] = RandomU64::rotl($this->state[3], 45);

        return $r;
    }

    private static function splitmix64(RandomU64 &$seed): RandomU64
    {
        $seed = RandomU64::add($seed, RandomU64::fromHex64(0x9E3779B9, 0x7F4A7C15));
        $r = RandomU64::xor($seed, $seed->shiftRight(30));
        $r = RandomU64::mul64($r, RandomU64::fromHex64(0xBF58476D, 0x1CE4E5B9));
        $r = RandomU64::xor($r, $r->shiftRight(27));
        $r = RandomU64::mul64($r, RandomU64::fromHex64(0x94D049BB, 0x133111EB));

        return RandomU64::xor($r, $r->shiftRight(31));
    }

    /** @return array<int, string> */
    public function exportSerializedState(): array
    {
        $out = [];
        foreach ($this->state as $i => $word) {
            $out[$i] = \sprintf('%08x%08x', $word->hi, $word->lo);
        }

        return $out;
    }

    /** @param array<int|string, string|int> $data */
    public function restoreFromSerializedState(array $data): void
    {
        for ($i = 0; $i < 4; ++$i) {
            $hex = \str_pad((string) ($data[$i] ?? '0'), 16, '0', STR_PAD_LEFT);
            $this->state[$i] = RandomU64::fromParts((int) \hexdec(\substr($hex, 0, 8)), (int) \hexdec(\substr($hex, 8, 8)));
        }
    }
}
