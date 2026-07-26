<?php

declare(strict_types=1);

namespace PHPCompiler\ext\hash;

use PHPCompiler\ext\standard\VmHashNative;
use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * HashContext::__serialize / __unserialize — php-src ext/hash/hash.c (#22331).
 *
 * Bag shape: [algo, options, context_state, magic=2, properties].
 * Context state follows each algo's serialize_spec (SHA256 "l8l2b64.", SHA1 "l5l2b64.",
 * MD5 "llllllb64l16.").
 */
final class HashContextSerializeSupport
{
    /** php-src PHP_HASH_SERIALIZE_MAGIC_SPEC */
    public const MAGIC_SPEC = 2;

    public static function registerMagicMethods(ClassEntry $entry): void
    {
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry->methods['__serialize'] = new HashContextSerialize();
        $entry->methodVisibility['__serialize'] = $pub;
        $entry->methodNames['__serialize'] = '__serialize';
        $entry->methods['__unserialize'] = new HashContextUnserialize();
        $entry->methodVisibility['__unserialize'] = $pub;
        $entry->methodNames['__unserialize'] = '__unserialize';
    }

    public static function exportSerializeBag(ObjectEntry $entry): Variable
    {
        $state = VmHashContext::exportStoreState($entry);
        if (null === $state) {
            throw new \Exception(
                'HashContext for algorithm "unknown" cannot be serialized'
            );
        }
        $algoName = VmHashNative::resolveAlgoName($state['algo']);
        if (0 !== ($state['flags'] & VmHashContext::HASH_HMAC) || null !== $state['hmacKey']) {
            throw new \Exception('HashContext with HASH_HMAC option cannot be serialized');
        }
        $ctxBag = self::exportContextState($state['algo'], $state['ctx']);
        if (null === $ctxBag) {
            throw new \Exception(
                'HashContext for algorithm "'.$algoName.'" cannot be serialized'
            );
        }

        return VmJson::import([
            0 => $algoName,
            1 => $state['flags'],
            2 => $ctxBag,
            3 => self::MAGIC_SPEC,
            4 => [],
        ]);
    }

    public static function restoreFromSerializeBag(ObjectEntry $object, Variable $data): void
    {
        if (VmHashContext::hasStore($object)) {
            throw new \Exception('HashContext::__unserialize called on initialized object');
        }
        $slots = [];
        foreach ($data->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $key->type) {
                continue;
            }
            $slots[$key->toInt()] = $valueVar->resolveIndirect();
        }
        if (
            !isset($slots[0], $slots[1], $slots[2], $slots[3], $slots[4])
            || Variable::TYPE_STRING !== $slots[0]->type
            || Variable::TYPE_INTEGER !== $slots[1]->type
            || Variable::TYPE_INTEGER !== $slots[3]->type
            || Variable::TYPE_ARRAY !== $slots[4]->type
        ) {
            throw new \Exception('Incomplete or ill-formed serialization data');
        }
        $options = $slots[1]->toInt();
        if (0 !== ($options & 0x0001)) { // PHP_HASH_HMAC
            throw new \Exception('HashContext with HASH_HMAC option cannot be serialized');
        }
        $magic = $slots[3]->toInt();
        if (self::MAGIC_SPEC !== $magic) {
            throw new \Exception('Incomplete or ill-formed serialization data');
        }
        $algoName = $slots[0]->toString();
        $algoId = VmHashNative::resolveAlgoId($algoName);
        if (0 === $algoId) {
            throw new \Exception(
                'Incomplete or ill-formed serialization data'
            );
        }
        if (Variable::TYPE_ARRAY !== $slots[2]->type) {
            throw new \Exception(
                'Incomplete or ill-formed serialization data ("'.$algoName.'" code -1)'
            );
        }
        $ctxElements = [];
        foreach ($slots[2]->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $key->type) {
                continue;
            }
            $ctxElements[$key->toInt()] = $valueVar->resolveIndirect();
        }
        \ksort($ctxElements);
        $ctx = self::importContextState($algoId, $algoName, $ctxElements);
        $object->constructed = true;
        VmHashContext::bindStore($object, $algoId, $ctx, false);
    }

    /**
     * @param array<string, mixed> $ctx
     *
     * @return list<mixed>|null
     */
    private static function exportContextState(int $algoId, array $ctx): ?array
    {
        if (1 === $algoId) {
            // PHP_SHA256_SPEC "l8l2b64."
            $totalBits = (int) $ctx['bitlen'] + ((int) $ctx['datalen'] * 8);
            $buf = self::padBuffer((string) $ctx['data'], 64);
            $out = [];
            foreach ($ctx['state'] as $word) {
                $out[] = self::toSigned32((int) $word);
            }
            $out[] = self::toSigned32($totalBits & 0xFFFFFFFF);
            $out[] = self::toSigned32(($totalBits >> 32) & 0xFFFFFFFF);
            $out[] = $buf;

            return $out;
        }
        if (2 === $algoId) {
            // PHP_SHA1_SPEC "l5l2b64."
            $out = [];
            foreach ($ctx['state'] as $word) {
                $out[] = self::toSigned32((int) $word);
            }
            $out[] = self::toSigned32((int) $ctx['count'][0]);
            $out[] = self::toSigned32((int) $ctx['count'][1]);
            $out[] = self::padBuffer((string) $ctx['buffer'], 64);

            return $out;
        }
        if (3 === $algoId) {
            // PHP_MD5_SPEC "llllllb64l16." — lo/hi byte counters + abcd + buffer + block[16]
            $count0 = self::u32((int) $ctx['count'][0]);
            $count1 = self::u32((int) $ctx['count'][1]);
            $totalBytes = ($count0 >> 3) + (($count1 & 0x1FFFFFFF) << 29);
            $lo = $totalBytes & 0x1FFFFFFF;
            $hi = $totalBytes >> 29;
            $out = [
                self::toSigned32($lo),
                self::toSigned32($hi),
                self::toSigned32((int) $ctx['state'][0]),
                self::toSigned32((int) $ctx['state'][1]),
                self::toSigned32((int) $ctx['state'][2]),
                self::toSigned32((int) $ctx['state'][3]),
                self::padBuffer((string) $ctx['buffer'], 64),
            ];
            for ($i = 0; $i < 16; ++$i) {
                $out[] = 0;
            }

            return $out;
        }

        return null;
    }

    /**
     * @param array<int, Variable> $elements
     *
     * @return array<string, mixed>
     */
    private static function importContextState(int $algoId, string $algoName, array $elements): array
    {
        if (1 === $algoId) {
            if (\count($elements) < 11
                || Variable::TYPE_STRING !== $elements[10]->type
            ) {
                throw new \Exception(
                    'Incomplete or ill-formed serialization data ("'.$algoName.'" code -1)'
                );
            }
            for ($i = 0; $i < 10; ++$i) {
                if (!isset($elements[$i]) || Variable::TYPE_INTEGER !== $elements[$i]->type) {
                    throw new \Exception(
                        'Incomplete or ill-formed serialization data ("'.$algoName.'" code -1)'
                    );
                }
            }
            $state = [];
            for ($i = 0; $i < 8; ++$i) {
                $state[] = self::u32($elements[$i]->toInt());
            }
            $count0 = self::u32($elements[8]->toInt());
            $count1 = self::u32($elements[9]->toInt());
            $totalBits = $count0 + ($count1 << 32);
            $datalen = ($count0 >> 3) & 0x3F;
            $bitlen = $totalBits - ($datalen * 8);
            if ($bitlen < 0) {
                throw new \Exception(
                    'Incomplete or ill-formed serialization data ("'.$algoName.'" code -1)'
                );
            }
            $buf = self::padBuffer($elements[10]->toString(), 64);

            return [
                'data' => $buf,
                'datalen' => $datalen,
                'bitlen' => $bitlen,
                'state' => $state,
            ];
        }
        if (2 === $algoId) {
            if (\count($elements) < 8
                || Variable::TYPE_STRING !== $elements[7]->type
            ) {
                throw new \Exception(
                    'Incomplete or ill-formed serialization data ("'.$algoName.'" code -1)'
                );
            }
            for ($i = 0; $i < 7; ++$i) {
                if (!isset($elements[$i]) || Variable::TYPE_INTEGER !== $elements[$i]->type) {
                    throw new \Exception(
                        'Incomplete or ill-formed serialization data ("'.$algoName.'" code -1)'
                    );
                }
            }
            $state = [];
            for ($i = 0; $i < 5; ++$i) {
                $state[] = self::u32($elements[$i]->toInt());
            }

            return [
                'state' => $state,
                'count' => [
                    self::u32($elements[5]->toInt()),
                    self::u32($elements[6]->toInt()),
                ],
                'buffer' => self::padBuffer($elements[7]->toString(), 64),
            ];
        }
        if (3 === $algoId) {
            if (\count($elements) < 7
                || Variable::TYPE_STRING !== $elements[6]->type
            ) {
                throw new \Exception(
                    'Incomplete or ill-formed serialization data ("'.$algoName.'" code -1)'
                );
            }
            for ($i = 0; $i < 6; ++$i) {
                if (!isset($elements[$i]) || Variable::TYPE_INTEGER !== $elements[$i]->type) {
                    throw new \Exception(
                        'Incomplete or ill-formed serialization data ("'.$algoName.'" code -1)'
                    );
                }
            }
            $lo = self::u32($elements[0]->toInt()) & 0x1FFFFFFF;
            $hi = self::u32($elements[1]->toInt());
            $totalBytes = $lo + ($hi << 29);
            $count0 = self::u32($totalBytes << 3);
            $count1 = self::u32($totalBytes >> 29);

            return [
                'count' => [$count0, $count1],
                'state' => [
                    self::u32($elements[2]->toInt()),
                    self::u32($elements[3]->toInt()),
                    self::u32($elements[4]->toInt()),
                    self::u32($elements[5]->toInt()),
                ],
                'buffer' => self::padBuffer($elements[6]->toString(), 64),
            ];
        }

        throw new \Exception(
            'Hash algorithm "'.$algoName.'" cannot be unserialized'
        );
    }

    private static function padBuffer(string $buf, int $len): string
    {
        if (\strlen($buf) >= $len) {
            return \substr($buf, 0, $len);
        }

        return $buf.\str_repeat("\0", $len - \strlen($buf));
    }

    private static function toSigned32(int $u): int
    {
        $u &= 0xFFFFFFFF;
        if ($u >= 0x80000000) {
            return $u - 0x100000000;
        }

        return $u;
    }

    private static function u32(int $v): int
    {
        return $v & 0xFFFFFFFF;
    }
}

/** php-src HashContext::__serialize (#22331). */
final class HashContextSerialize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__serialize');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('HashContext::__serialize() expects a HashContext receiver');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('HashContext::__serialize() expects a HashContext receiver');
        }
        $object = $receiver->toObject();
        if (VmHashContext::CLASS_LC !== strtolower($object->class->name)) {
            throw new \LogicException('HashContext::__serialize() expects a HashContext receiver');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(
            HashContextSerializeSupport::exportSerializeBag($object)->resolveIndirect()
        );
    }
}

/** php-src HashContext::__unserialize (#22331). */
final class HashContextUnserialize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__unserialize');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('HashContext::__unserialize() expects a HashContext receiver');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('HashContext::__unserialize() expects a HashContext receiver');
        }
        $object = $receiver->toObject();
        if (VmHashContext::CLASS_LC !== strtolower($object->class->name)) {
            throw new \LogicException('HashContext::__unserialize() expects a HashContext receiver');
        }
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'HashContext::__unserialize() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $arg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(
                'HashContext::__unserialize(): Argument #1 ($data) must be of type array'
            );
        }
        HashContextSerializeSupport::restoreFromSerializeBag($object, $arg);
    }
}
