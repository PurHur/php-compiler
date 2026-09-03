#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Weekly "closed but not done" audit (#36400).
 *
 * Finds issues closed in the last N days whose closing PR body looks partial
 * ("remain", "follow-up", "partial", "part of") or whose Done-when boxes were
 * not all ticked in that PR. Labels them `needs-respin` and can post one
 * summary on the active Wave tracker.
 *
 * Usage:
 *   php script/audit-closed-but-partial.php --days 7 --dry-run
 *   php script/audit-closed-but-partial.php --days 7 --post --tracker 36379
 *   php script/audit-closed-but-partial.php --days 7 --post --apply-labels
 *   php script/audit-closed-but-partial.php --fixture FILE --dry-run
 *
 * Default: posts an umbrella child + tracker comment; pass --apply-labels to
 * also stamp `needs-respin` on each flagged closed issue.
 */

$root = dirname(__DIR__);
$days = 7;
$dryRun = in_array('--dry-run', $argv, true);
$post = in_array('--post', $argv, true);
$applyLabels = in_array('--apply-labels', $argv, true);
$tracker = 36379;
$repo = 'PurHur/php-compiler';
$fixture = null;
$label = 'needs-respin';

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--days' && isset($argv[$i + 1])) {
        $days = max(1, (int) $argv[++$i]);
        continue;
    }
    if ($arg === '--tracker' && isset($argv[$i + 1])) {
        $tracker = (int) $argv[++$i];
        continue;
    }
    if ($arg === '--repo' && isset($argv[$i + 1])) {
        $repo = $argv[++$i];
        continue;
    }
    if ($arg === '--fixture' && isset($argv[$i + 1])) {
        $fixture = $argv[++$i];
        continue;
    }
    if ($arg === '--label' && isset($argv[$i + 1])) {
        $label = $argv[++$i];
        continue;
    }
}

exit(main_audit($root, $days, $dryRun, $post, $applyLabels, $tracker, $repo, $fixture, $label));

function main_audit(
    string $root,
    int $days,
    bool $dryRun,
    bool $post,
    bool $applyLabels,
    int $tracker,
    string $repo,
    ?string $fixture,
    string $label
): int {
    $rows = $fixture !== null
        ? load_fixture_rows($fixture)
        : fetch_recently_closed_rows($repo, $days);

    $flagged = [];
    foreach ($rows as $row) {
        $reason = classify_partial_close($row);
        if ($reason === null) {
            continue;
        }
        $flagged[] = [
            'number' => (int) $row['number'],
            'title' => (string) ($row['title'] ?? ''),
            'reason' => $reason,
            'prUrl' => (string) ($row['prUrl'] ?? ''),
        ];
    }

    if ($flagged === []) {
        fwrite(STDOUT, "audit-closed-but-partial: none flagged in last {$days} day(s)\n");
        if ($post && !$dryRun) {
            $body = build_audit_comment($days, $flagged, $repo, null);
            post_tracker_comment($repo, $tracker, $body);
            fwrite(STDOUT, "audit-closed-but-partial: posted empty-result summary on #{$tracker}\n");
        }

        return 0;
    }

    fwrite(STDOUT, 'audit-closed-but-partial: '.count($flagged)." candidate(s):\n");
    foreach ($flagged as $f) {
        fwrite(STDOUT, "  #{$f['number']}\t{$f['reason']}\t{$f['title']}\n");
    }

    if ($dryRun) {
        fwrite(STDOUT, "audit-closed-but-partial: dry-run — not labeling or posting\n");

        return 0;
    }

    $umbrella = null;
    if ($post) {
        $umbrella = create_umbrella_issue($repo, $days, $flagged);
        fwrite(STDOUT, "audit-closed-but-partial: umbrella child #{$umbrella}\n");
    }

    if ($applyLabels) {
        ensure_label($repo, $label);
        foreach ($flagged as $f) {
            apply_label($repo, $f['number'], $label);
            fwrite(STDOUT, "audit-closed-but-partial: labeled #{$f['number']} {$label}\n");
        }
        if ($umbrella !== null) {
            apply_label($repo, $umbrella, $label);
        }
    } else {
        fwrite(STDOUT, "audit-closed-but-partial: skipping per-issue labels (pass --apply-labels to opt in)\n");
    }

    if ($post) {
        $body = build_audit_comment($days, $flagged, $repo, $umbrella);
        post_tracker_comment($repo, $tracker, $body);
        fwrite(STDOUT, "audit-closed-but-partial: posted summary on #{$tracker}\n");
    }

    return 0;
}

/**
 * @return list<array<string,mixed>>
 */
function load_fixture_rows(string $path): array
{
    if (!is_readable($path)) {
        fwrite(STDERR, "audit-closed-but-partial: fixture not readable: {$path}\n");
        exit(1);
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        fwrite(STDERR, "audit-closed-but-partial: fixture must be a JSON array\n");
        exit(1);
    }

    return $data;
}

/**
 * @return list<array<string,mixed>>
 */
function fetch_recently_closed_rows(string $repo, int $days): array
{
    $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('-'.$days.' days')
        ->format('Y-m-d');
    $cmd = 'gh issue list --repo '.escapeshellarg($repo)
        .' --state closed --limit 100 --search '.escapeshellarg("closed:>={$since}")
        .' --json number,title,closedAt,url 2>/dev/null';
    $json = shell_exec($cmd);
    if ($json === null || $json === '') {
        fwrite(STDERR, "audit-closed-but-partial: gh issue list failed\n");
        exit(1);
    }
    $issues = json_decode($json, true);
    if (!is_array($issues)) {
        return [];
    }

    $rows = [];
    foreach ($issues as $issue) {
        $n = (int) ($issue['number'] ?? 0);
        if ($n <= 0) {
            continue;
        }
        $pr = find_closing_pr($repo, $n);
        $issueBody = fetch_issue_body_raw($repo, $n);
        $rows[] = [
            'number' => $n,
            'title' => (string) ($issue['title'] ?? ''),
            'closedAt' => (string) ($issue['closedAt'] ?? ''),
            'closingPrBody' => $pr['body'] ?? '',
            'prUrl' => $pr['url'] ?? '',
            'issueBody' => $issueBody ?? '',
        ];
    }

    return $rows;
}

/**
 * @return array{body?:string,url?:string}
 */
function find_closing_pr(string $repo, int $issueNumber): array
{
    // Prefer timeline events via gh api
    $cmd = 'gh api repos/'.escapeshellarg($repo).'/issues/'.$issueNumber.'/timeline --paginate 2>/dev/null';
    // gh api with escapeshellarg on repo breaks the path — build carefully
    $cmd = 'gh api '.escapeshellarg("repos/{$repo}/issues/{$issueNumber}/timeline").' --paginate 2>/dev/null';
    $json = shell_exec($cmd);
    if ($json === null || $json === '') {
        return [];
    }
    // --paginate may concatenate arrays; try decode
    $events = json_decode($json, true);
    if (!is_array($events)) {
        return [];
    }
    foreach (array_reverse($events) as $ev) {
        if (($ev['event'] ?? '') !== 'closed' && ($ev['event'] ?? '') !== 'cross-referenced') {
            // look for connected PR
        }
        $src = $ev['source'] ?? null;
        if (is_array($src) && isset($src['issue']['pull_request'])) {
            $prNum = (int) ($src['issue']['number'] ?? 0);
            if ($prNum > 0) {
                return fetch_pr($repo, $prNum);
            }
        }
    }
    // Fallback: search PRs that mention Closes #N
    $cmd = 'gh pr list --repo '.escapeshellarg($repo)
        .' --state merged --limit 20 --search '.escapeshellarg((string) $issueNumber)
        .' --json number,url,body 2>/dev/null';
    $list = json_decode((string) shell_exec($cmd), true);
    if (!is_array($list)) {
        return [];
    }
    foreach ($list as $pr) {
        $body = (string) ($pr['body'] ?? '');
        if (preg_match('/\b(?:close[sd]?|fix(?:e[sd])?|resolve[sd]?)\s+#'.$issueNumber.'\b/i', $body)) {
            return ['body' => $body, 'url' => (string) ($pr['url'] ?? '')];
        }
    }

    return [];
}

/**
 * @return array{body?:string,url?:string}
 */
function fetch_pr(string $repo, int $number): array
{
    $cmd = 'gh pr view '.escapeshellarg((string) $number)
        .' --repo '.escapeshellarg($repo)
        .' --json body,url 2>/dev/null';
    $data = json_decode((string) shell_exec($cmd), true);
    if (!is_array($data)) {
        return [];
    }

    return ['body' => (string) ($data['body'] ?? ''), 'url' => (string) ($data['url'] ?? '')];
}

function fetch_issue_body_raw(string $repo, int $number): ?string
{
    $cmd = 'gh issue view '.escapeshellarg((string) $number)
        .' --repo '.escapeshellarg($repo)
        .' --json body -q .body 2>/dev/null';
    $out = shell_exec($cmd);
    if ($out === null || $out === '') {
        return null;
    }

    return $out;
}

/**
 * @param array<string,mixed> $row
 */
function classify_partial_close(array $row): ?string
{
    $prBody = (string) ($row['closingPrBody'] ?? '');
    $issueBody = (string) ($row['issueBody'] ?? '');
    $n = (int) ($row['number'] ?? 0);

    if ($prBody === '') {
        return null; // no closing PR found — skip (manual close)
    }

    // Explicit partial language in the closing PR (narrow patterns — avoid "partial" in prose titles)
    if (preg_match('/\bPart of #\d+\b/i', $prBody)
        || preg_match('/\b(?:follow-?up|remain(?:s|ing)?)\b/i', $prBody)
        || preg_match('/\bpartial\s+(?:merge|only|slice|fix|land|work)\b/i', $prBody)
    ) {
        // Only flag when the same PR also claims Closes #N for this issue
        if (preg_match('/\b(?:close[sd]?|fix(?:e[sd])?|resolve[sd]?)\s+#'.$n.'\b/i', $prBody)) {
            return 'closing PR both Closes #'.$n.' and uses partial/follow-up language';
        }
    }

    // Closes #N without ticked Done-when
    if ($issueBody !== '' && preg_match('/\b(?:close[sd]?|fix(?:e[sd])?|resolve[sd]?)\s+#'.$n.'\b/i', $prBody)) {
        $required = audit_extract_done_when_items($issueBody);
        if ($required === []) {
            return 'Closes #'.$n.' but issue has no Done-when checklist';
        }
        $ticked = audit_extract_ticked_items($prBody);
        $tickedSet = array_fill_keys($ticked, true);
        foreach ($required as $item) {
            if (!isset($tickedSet[$item])) {
                return 'Closes #'.$n.' without ticked Done-when item: '.$item;
            }
        }
    }

    return null;
}

/** @return list<string> */
function audit_extract_done_when_items(string $issueBody): array
{
    if (!preg_match('/^##\s+Done when\s*$/mi', $issueBody, $m, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    $start = (int) $m[0][1] + strlen($m[0][0]);
    $rest = substr($issueBody, $start);
    if (preg_match('/^##\s+/m', $rest, $next, PREG_OFFSET_CAPTURE)) {
        $rest = substr($rest, 0, (int) $next[0][1]);
    }
    $items = [];
    if (preg_match_all('/^\s*-\s*\[([ xX])\]\s*(.+?)\s*$/m', $rest, $boxes, PREG_SET_ORDER)) {
        foreach ($boxes as $box) {
            $text = audit_normalize($box[2]);
            if ($text !== '') {
                $items[] = $text;
            }
        }
    }

    return $items;
}

/** @return list<string> */
function audit_extract_ticked_items(string $prBody): array
{
    $items = [];
    if (preg_match_all('/^\s*-\s*\[[xX]\]\s*(.+?)\s*$/m', $prBody, $boxes, PREG_SET_ORDER)) {
        foreach ($boxes as $box) {
            $text = audit_normalize($box[1]);
            if ($text !== '') {
                $items[] = $text;
            }
        }
    }

    return $items;
}

function audit_normalize(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

    return $text;
}

/**
 * @param list<array{number:int,title:string,reason:string,prUrl:string}> $flagged
 */
function build_audit_comment(int $days, array $flagged, string $repo, ?int $umbrella): string
{
    $date = gmdate('Y-m-d');
    $lines = [];
    $lines[] = "## Closed-but-partial audit ({$date}, last {$days} days) — #36400";
    $lines[] = '';
    if ($flagged === []) {
        $lines[] = 'No closed issues in the window matched partial-close heuristics (`Closes #N` + partial/follow-up language, or `Closes #N` without a full ticked Done-when copy).';
        $lines[] = '';
        $lines[] = 'Label `needs-respin` was not applied.';

        return implode("\n", $lines)."\n";
    }
    $lines[] = 'Flagged **'.count($flagged).'** issue(s):';
    $lines[] = '';
    foreach ($flagged as $f) {
        $link = "https://github.com/{$repo}/issues/{$f['number']}";
        $pr = $f['prUrl'] !== '' ? " — PR: {$f['prUrl']}" : '';
        $lines[] = "- [#{$f['number']}]({$link}) — {$f['reason']}{$pr}";
    }
    $lines[] = '';
    if ($umbrella !== null) {
        $lines[] = "Umbrella respin child: [#{$umbrella}](https://github.com/{$repo}/issues/{$umbrella}) (queue for fleet — do not mass-reopen here).";
    } else {
        $lines[] = 'Fleet: open a fresh child (or re-open) for each entry before claiming that scope again.';
    }

    return implode("\n", $lines)."\n";
}

/**
 * @param list<array{number:int,title:string,reason:string,prUrl:string}> $flagged
 */
function create_umbrella_issue(string $repo, int $days, array $flagged): int
{
    $date = gmdate('Y-m-d');
    $title = "Respin queue from #36400 closed-but-partial audit ({$date})";
    $bodyLines = [];
    $bodyLines[] = "Parent: #36400 / tracker #36379";
    $bodyLines[] = '';
    $bodyLines[] = "First weekly audit found **".count($flagged)."** closed issues in the last {$days} days whose closing PR looks partial under the #36400 heuristics.";
    $bodyLines[] = '';
    $bodyLines[] = '## Queue';
    $bodyLines[] = '';
    foreach ($flagged as $f) {
        $bodyLines[] = "- #{$f['number']} — {$f['reason']} — {$f['title']}";
    }
    $bodyLines[] = '';
    $bodyLines[] = '## Done when';
    $bodyLines[] = '';
    $bodyLines[] = '- [ ] Each queue entry either has an open successor child covering the remaining Done-when, or is confirmed complete and removed from this list';
    $bodyLines[] = '- [ ] No new `Closes #N` lands without a full ticked Done-when copy (`script/check-issue-close-scope.php`)';
    $body = implode("\n", $bodyLines)."\n";
    $tmp = tempnam(sys_get_temp_dir(), 'umbrella36400-');
    if ($tmp === false) {
        fwrite(STDERR, "audit-closed-but-partial: tempnam failed for umbrella\n");
        exit(1);
    }
    file_put_contents($tmp, $body);
    ensure_label($repo, 'needs-respin');
    $cmd = 'gh issue create --repo '.escapeshellarg($repo)
        .' --title '.escapeshellarg($title)
        .' --body-file '.escapeshellarg($tmp)
        .' --label '.escapeshellarg('needs-respin')
        .' 2>&1';
    exec($cmd, $out, $code);
    if ($code !== 0) {
        $out = [];
        $cmd = 'gh issue create --repo '.escapeshellarg($repo)
            .' --title '.escapeshellarg($title)
            .' --body-file '.escapeshellarg($tmp)
            .' 2>&1';
        exec($cmd, $out, $code);
    }
    @unlink($tmp);
    $text = implode("\n", $out);
    if ($code !== 0 || !preg_match('#/issues/(\d+)#', $text, $m)) {
        fwrite(STDERR, "audit-closed-but-partial: failed to create umbrella issue: {$text}\n");
        exit(1);
    }

    return (int) $m[1];
}

function ensure_label(string $repo, string $label): void
{
    $cmd = 'gh label list --repo '.escapeshellarg($repo).' --limit 200 --json name -q '."'.[].name' 2>/dev/null";
    // simpler:
    $cmd = 'gh label list --repo '.escapeshellarg($repo).' --limit 200 --json name 2>/dev/null';
    $data = json_decode((string) shell_exec($cmd), true);
    $names = [];
    if (is_array($data)) {
        foreach ($data as $row) {
            $names[] = (string) ($row['name'] ?? '');
        }
    }
    if (in_array($label, $names, true)) {
        return;
    }
    $create = 'gh label create '.escapeshellarg($label)
        .' --repo '.escapeshellarg($repo)
        .' --description '.escapeshellarg('Closed before Done-when was complete; needs a respin child (#36400)')
        .' --color '.escapeshellarg('B60205').' 2>&1';
    exec($create, $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "audit-closed-but-partial: could not create label {$label}: ".implode("\n", $out)."\n");
    }
}

function apply_label(string $repo, int $number, string $label): void
{
    $cmd = 'gh issue edit '.escapeshellarg((string) $number)
        .' --repo '.escapeshellarg($repo)
        .' --add-label '.escapeshellarg($label).' 2>&1';
    exec($cmd, $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "audit-closed-but-partial: failed to label #{$number}: ".implode("\n", $out)."\n");
    }
}

function post_tracker_comment(string $repo, int $tracker, string $body): void
{
    $tmp = tempnam(sys_get_temp_dir(), 'audit36400-');
    if ($tmp === false) {
        fwrite(STDERR, "audit-closed-but-partial: tempnam failed\n");
        exit(1);
    }
    file_put_contents($tmp, $body);
    $cmd = 'gh issue comment '.escapeshellarg((string) $tracker)
        .' --repo '.escapeshellarg($repo)
        .' --body-file '.escapeshellarg($tmp).' 2>&1';
    exec($cmd, $out, $code);
    @unlink($tmp);
    if ($code !== 0) {
        fwrite(STDERR, "audit-closed-but-partial: failed to comment on #{$tracker}: ".implode("\n", $out)."\n");
        exit(1);
    }
}
