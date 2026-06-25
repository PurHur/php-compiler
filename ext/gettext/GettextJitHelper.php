<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gettext;

/**
 * ext/gettext builtins for compiled JIT/AOT modules (#9859, php-in-PHP).
 *
 * SSOT: {@see VmGettextNative}
 * php-src: ext/gettext/gettext.c
 */
final class GettextJitHelper
{
    public static function gettextArgv(string $msgid): string
    {
        return VmGettextNative::gettext($msgid);
    }

    public static function dgettextArgv(string $domain, string $msgid): string
    {
        return VmGettextNative::dgettext($domain, $msgid);
    }

    public static function dcgettextArgv(string $domain, string $msgid, int $category): string
    {
        return VmGettextNative::dcgettext($domain, $msgid, $category);
    }

    public static function dngettextArgv(string $domain, string $msgid1, string $msgid2, int $count): string
    {
        return VmGettextNative::dngettext($domain, $msgid1, $msgid2, $count);
    }

    public static function dcngettextArgv(
        string $domain,
        string $msgid1,
        string $msgid2,
        int $count,
        int $category
    ): string {
        return VmGettextNative::dcngettext($domain, $msgid1, $msgid2, $count, $category);
    }

    public static function bindtextdomainQuery(string $domain): ?string
    {
        $result = VmGettextNative::bindtextdomain($domain, null);

        return false === $result ? null : $result;
    }

    public static function bindtextdomainSet(string $domain, string $directory): ?string
    {
        $result = VmGettextNative::bindtextdomain($domain, $directory);

        return false === $result ? null : $result;
    }

    public static function textdomainQuery(): ?string
    {
        $result = VmGettextNative::textdomain(null);

        return false === $result ? null : $result;
    }

    public static function textdomainSet(string $domain): ?string
    {
        $result = VmGettextNative::textdomain($domain);

        return false === $result ? null : $result;
    }

    public static function bindTextdomainCodesetQuery(string $domain): ?string
    {
        $result = VmGettextNative::bindTextdomainCodeset($domain, null);

        return false === $result ? null : $result;
    }

    public static function bindTextdomainCodesetSet(string $domain, string $codeset): ?string
    {
        $result = VmGettextNative::bindTextdomainCodeset($domain, $codeset);

        return false === $result ? null : $result;
    }
}
