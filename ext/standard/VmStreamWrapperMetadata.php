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

    /** STREAM_META_TOUCH value — optional mtime/atime list (php-src php_touch wrapper path). */
    public static function touchValue(?int $mtime, ?int $atime): Variable
    {
        $ht = new HashTable();
        $index = 0;
        if (null !== $mtime) {
            $v = new Variable();
            $v->int($mtime);
            $ht->add((string) $index, $v);
            ++$index;
        }
        if (null !== $atime) {
            $v = new Variable();
            $v->int($atime);
            $ht->add((string) $index, $v);
        }
        $arr = new Variable();
        $arr->array($ht);

        return $arr;
    }
}
