<?php

declare(strict_types=1);

/**
 * Check composer.lock content-hash against composer.json (#15620, #15621).
 *
 * Replicates Composer\Package\Locker::getContentHash() so the drift gate
 * does not need a composer binary. Exit 1 when the lock is stale.
 */

$root = dirname(__DIR__);

$json = file_get_contents($root . '/composer.json');
$lockRaw = file_get_contents($root . '/composer.lock');
if (false === $json || false === $lockRaw) {
    fwrite(STDERR, "check-composer-lock-hash: cannot read composer.json/composer.lock\n");
    exit(1);
}

$content = json_decode($json, true);
$lock = json_decode($lockRaw, true);
if (!\is_array($content) || !\is_array($lock) || !isset($lock['content-hash'])) {
    fwrite(STDERR, "check-composer-lock-hash: malformed composer.json/composer.lock\n");
    exit(1);
}

$relevantKeys = [
    'name', 'version', 'require', 'require-dev', 'conflict', 'replace',
    'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra',
];
$relevantContent = [];
foreach (array_intersect($relevantKeys, array_keys($content)) as $key) {
    $relevantContent[$key] = $content[$key];
}
if (isset($content['config']['platform'])) {
    $relevantContent['config']['platform'] = $content['config']['platform'];
}
ksort($relevantContent);
$expected = md5(json_encode($relevantContent));

if ($lock['content-hash'] !== $expected) {
    fwrite(STDERR, sprintf(
        "check-composer-lock-hash: STALE — lock content-hash %s != %s from composer.json; refresh the lock in the pinned env (#15620)\n",
        $lock['content-hash'],
        $expected
    ));
    exit(1);
}

echo "check-composer-lock-hash: OK (content-hash {$expected})\n";
