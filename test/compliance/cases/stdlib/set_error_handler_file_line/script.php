<?php
declare(strict_types=1);

$info = [];
set_error_handler(static function (int $s, string $m, string $f, int $l) use (&$info): bool {
    $info = ['severity' => $s, 'file' => $f, 'line' => $l];

    return true;
});
file_get_contents('/nope/phpc-eh-site-'.getmypid());
echo 'severity=', $info['severity'] ?? -1, "\n";
echo 'file_match=', (($info['file'] ?? '') === __FILE__) ? 'yes' : 'no', "\n";
echo 'line_gt0=', (($info['line'] ?? 0) > 0) ? 'yes' : 'no', "\n";
