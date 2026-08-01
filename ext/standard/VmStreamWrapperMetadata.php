<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * User stream wrapper stream_metadata dispatch (php-src filestat.c + userspace.c; #8689).
 *
 * touch()/chmod()/chown()/chgrp() on custom protocol URLs invoke the wrapper's
 * stream_metadata() when registered — no host filesystem path.
 */
final class VmStreamWrapperMetadata
{
    /**
     * Invoke stream_metadata on a registered custom wrapper when present.
     *
     * @return bool|null null when $uri is not a custom protocol (native handling applies)
     */
    public static function tryInvoke(string $uri, int $option, Variable $value): ?bool
    {
        if (!VmStreamWrapperRegistry::isCustomProtocol($uri)) {
            return null;
        }
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            return false;
        }
        $protocol = VmStreamWrapperRegistry::parseProtocol($uri);
        if (null === $protocol) {
            return false;
        }
        $className = VmStreamWrapperRegistry::lookupClass($protocol);
        if (null === $className) {
            return false;
        }
        $wrapper = VmUserStream::instantiateWrapper($ctx->runtime->vm, $ctx, $className);
        if (null === $wrapper) {
            return false;
        }
        if (!$ctx->runtime->vm->hasInstanceMethod($wrapper->class, 'stream_metadata')) {
            return false;
        }
        $pathVar = new Variable();
        $pathVar->string($uri);
        $optionVar = new Variable();
        $optionVar->int($option);
        $result = $ctx->runtime->vm->invokeInstanceMethod(
            $wrapper,
            'stream_metadata',
            $pathVar,
            $optionVar,
            $value
        )->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $result->type) {
            return $result->toBool();
        }

        return false;
    }

    /**
     * STREAM_META_TOUCH value — optional mtime/atime list (php-src php_touch + userspace.c).
     *
     * php-src filestat.c: when only mtime is set, both utimbuf fields get that mtime
     * (`newtime->modtime = newtime->actime = filetime`). userspace.c then always
     * exposes [modtime, actime] when the pointer is non-NULL — so two-arg touch
     * yields [mtime, mtime], not a single-element list (#26288).
     */
    public static function touchValue(?int $mtime, ?int $atime): Variable
    {
        $ht = new HashTable();
        // Both omitted → NULL utimbuf → empty array (zero-arg / null,null touch).
        if (null === $mtime && null === $atime) {
            $arr = new Variable();
            $arr->array($ht);

            return $arr;
        }
        // mtime set, atime omitted → duplicate mtime as actime (two-arg touch).
        if (null !== $mtime && null === $atime) {
            $atime = $mtime;
        }
        // mtime null + atime set is a ValueError in php-src before metadata dispatch;
        // callers must not reach here in that shape.
        $mod = new Variable();
        $mod->int((int) $mtime);
        $ht->add('0', $mod);
        $acc = new Variable();
        $acc->int((int) $atime);
        $ht->add('1', $acc);
        $arr = new Variable();
        $arr->array($ht);

        return $arr;
    }
}
