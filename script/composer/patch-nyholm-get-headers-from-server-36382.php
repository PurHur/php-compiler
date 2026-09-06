<?php

declare(strict_types=1);

/**
 * #36382 — AOT typed `: array` return of a foreach-built map with computed string keys
 * SEGVs / returns empty (ret3/ret2). Nyholm getHeadersFromServer is that shape.
 * Drop the return type so the hashtable escapes like the untyped path (Zend-equivalent
 * for callers that only foreach / index the result).
 *
 * Also drops `: array` on ServerRequestCreatorInterface in the same directory so Zend
 * does not fatal on declaration compatibility.
 *
 * Usage: php script/composer/patch-nyholm-get-headers-from-server-36382.php path/to/ServerRequestCreator.php
 */
$path = $argv[1] ?? '';
if ('' === $path || !is_file($path)) {
    fwrite(STDERR, "usage: patch-nyholm-get-headers-from-server-36382.php <ServerRequestCreator.php>\n");
    exit(1);
}

$patchIface = static function (string $creatorPath): void {
    $iface = dirname($creatorPath).'/ServerRequestCreatorInterface.php';
    if (!is_file($iface)) {
        return;
    }
    $ifaceText = file_get_contents($iface);
    if (false === $ifaceText || !str_contains($ifaceText, 'getHeadersFromServer(array $server): array;')) {
        return;
    }
    $ifaceText = str_replace(
        'getHeadersFromServer(array $server): array;',
        // AOT (#36382): match implementation — drop : array (peer ServerRequestCreator patch).
        'getHeadersFromServer(array $server);',
        $ifaceText,
        $ifaceCount
    );
    if (1 === $ifaceCount) {
        file_put_contents($iface, $ifaceText);
    }
};

$text = file_get_contents($path);
if (false === $text) {
    fwrite(STDERR, "cannot read $path\n");
    exit(1);
}

if (str_contains($text, 'AOT (#36382): typed array return')) {
    $patchIface($path);
    echo "already patched getHeadersFromServer for AOT (#36382)\n";
    exit(0);
}

$old = '    public static function getHeadersFromServer(array $server): array';
$new = "    // AOT (#36382): typed array return of foreach+computed keys SEGVs / empties under AOT.\n"
    .'    // Untyped return matches Zend observable result for callers (peer ret4 probe).'
    ."\n"
    .'    public static function getHeadersFromServer(array $server)';

if (!str_contains($text, $old)) {
    fwrite(STDERR, "getHeadersFromServer typed signature not found in $path\n");
    exit(1);
}

file_put_contents($path, str_replace($old, $new, $text, $count));
if (1 !== $count) {
    fwrite(STDERR, "expected 1 replacement, got $count\n");
    exit(1);
}

$patchIface($path);

echo "patched getHeadersFromServer for AOT (#36382)\n";
