<?php
// #36221 program: regex-heavy log parse + aggregate
$log = "2024-01-15T10:00:01Z INFO user=ada action=login ip=10.0.0.1\n"
    . "2024-01-15T10:00:02Z WARN user=ada action=retry ip=10.0.0.1\n"
    . "2024-01-15T10:00:03Z INFO user=grace action=login ip=10.0.0.2\n"
    . "2024-01-15T10:00:04Z ERROR user=alan action=deny ip=10.0.0.3\n"
    . "2024-01-15T10:00:05Z INFO user=grace action=view ip=10.0.0.2\n"
    . "2024-01-15T10:00:06Z INFO user=ada action=logout ip=10.0.0.1";
$re = '/^(\\d{4}-\\d{2}-\\d{2}T[\\d:]+Z)\\s+(\\w+)\\s+user=(\\w+)\\s+action=(\\w+)\\s+ip=([\\d.]+)$/';
$counts = ['INFO' => 0, 'WARN' => 0, 'ERROR' => 0];
$byUser = [];
$ips = [];
foreach (explode("\n", $log) as $line) {
    if (!preg_match($re, $line, $m)) {
        echo "BAD:$line\n";
        continue;
    }
    $lvl = $m[2];
    $user = $m[3];
    $action = $m[4];
    $ip = $m[5];
    if (!isset($counts[$lvl])) {
        $counts[$lvl] = 0;
    }
    $counts[$lvl]++;
    if (!isset($byUser[$user])) {
        $byUser[$user] = [];
    }
    $byUser[$user][] = $action;
    $ips[$ip] = true;
}
ksort($byUser);
$parts = [];
foreach ($counts as $k => $v) {
    $parts[] = "$k=$v";
}
$uParts = [];
foreach ($byUser as $u => $acts) {
    $uParts[] = $u . ':' . implode(',', $acts);
}
$out = implode(' ', $parts) . "\n" . implode('|', $uParts) . "\nips=" . count($ips) . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";
