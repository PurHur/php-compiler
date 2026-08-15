<?php

declare(strict_types=1);

namespace PHPCompiler\ext\random;

use PHPCompiler\ext\standard\VmString;

/** Random\\Engine\\PcgOneseq128XslRr64 (php-src engine_pcgoneseq128xslrr64.c; #11550). */
final class PcgOneseq128XslRr64Instance
{
    private RandomUint128 $state;

    private const MULT = [0x2360ED05, 0x1FC65DA4, 0x4385DF64, 0x9FCCF645];

    private const PLUS = [0x5851F42D, 0x4C957F2D, 0x14057B7E, 0xF767814F];

    public function __construct()
    {
        $this->state = RandomUint128::constant(0, 0, 0, 0);
    }

    public function seed128(RandomUint128 $seed): void
    {
        $this->state = RandomUint128::constant(0, 0, 0, 0);
        $this->step();
        $this->state = RandomUint128::add($this->state, $seed);
        $this->step();
    }

    public function seedFromInt(int $seed): void
    {
        $this->seed128(RandomUint128::constant(0, 0, 0, $seed & 0xFFFFFFFF));
    }

    public function seedFromBytes(string $bytes): void
    {
        if (16 !== \strlen($bytes)) {
            throw new \ValueError('Random\\Engine\\PcgOneseq128XslRr64::__construct(): Argument #1 ($seed) must be a 16 byte (128 bit) string');
        }
        $parts = \unpack('P2', $bytes);
        $hi = (int) ($parts[1] ?? 0);
        $lo = (int) ($parts[2] ?? 0);
        // php_random_uint128_constant(t[0], t[1]): first 8 bytes = hi, second 8 = lo (#31054).
        $this->seed128(RandomUint128::constant(
            ($hi >> 32) & 0xFFFFFFFF,
            $hi & 0xFFFFFFFF,
            ($lo >> 32) & 0xFFFFFFFF,
            $lo & 0xFFFFFFFF
        ));
    }

    public function seedRandom(): void
    {
        $this->seedFromBytes(VmString::randomBytes(16));
    }

    public function generate(): string
    {
        $this->step();

        return RandomUint128::pcgRotr64Bytes($this->state);
    }

    public function jump(int $advance): void
    {
        if ($advance < 0) {
            throw new \ValueError('Random\\Engine\\PcgOneseq128XslRr64::jump(): Argument #1 ($advance) must be greater than or equal to 0');
        }
        $curMult = RandomUint128::constant(...self::MULT);
        $curPlus = RandomUint128::constant(...self::PLUS);
        $accMult = RandomUint128::constant(0, 0, 0, 1);
        $accPlus = RandomUint128::constant(0, 0, 0, 0);
        while ($advance > 0) {
            if (1 === ($advance & 1)) {
                $accMult = RandomUint128::multiply($accMult, $curMult);
                $accPlus = RandomUint128::add(RandomUint128::multiply($accPlus, $curMult), $curPlus);
            }
            $curPlus = RandomUint128::multiply(RandomUint128::add($curMult, RandomUint128::constant(0, 0, 0, 1)), $curPlus);
            $curMult = RandomUint128::multiply($curMult, $curMult);
            $advance = intdiv($advance, 2);
        }
        $this->state = RandomUint128::add(RandomUint128::multiply($accMult, $this->state), $accPlus);
    }

    private function step(): void
    {
        $this->state = RandomUint128::add(
            RandomUint128::multiply($this->state, RandomUint128::constant(...self::MULT)),
            RandomUint128::constant(...self::PLUS)
        );
    }

    /** @return array<int, string> */
    public function exportSerializedState(): array
    {
        return [
            0 => \sprintf('%08x%08x', $this->state->hi->hi, $this->state->hi->lo),
            1 => \sprintf('%08x%08x', $this->state->lo->hi, $this->state->lo->lo),
        ];
    }

    /** @param array<int|string, string|int> $data */
    public function restoreFromSerializedState(array $data): void
    {
        $hiHex = \str_pad((string) ($data[0] ?? '0'), 16, '0', STR_PAD_LEFT);
        $loHex = \str_pad((string) ($data[1] ?? '0'), 16, '0', STR_PAD_LEFT);
        $this->state = RandomUint128::constant(
            (int) \hexdec(\substr($hiHex, 0, 8)),
            (int) \hexdec(\substr($hiHex, 8, 8)),
            (int) \hexdec(\substr($loHex, 0, 8)),
            (int) \hexdec(\substr($loHex, 8, 8))
        );
    }
}
