<?php
// #36221 program: end-to-end mini request handler
function validate(array $req): array {
    $errors = [];
    if (!isset($req['name']) || !is_string($req['name']) || $req['name'] === '') {
        $errors[] = 'name';
    }
    if (!isset($req['scores']) || !is_array($req['scores'])) {
        $errors[] = 'scores';
    }
    return $errors;
}
$raw = '{"name":"Ada","scores":[90,70,100,80],"note":"ok & fine"}';
$req = json_decode($raw, true);
$errors = validate($req);
if ($errors) {
    echo json_encode(['ok' => false, 'errors' => $errors]), "\n";
    exit(0);
}
$scores = [];
foreach ($req['scores'] as $s) {
    $scores[] = (int) $s;
}
sort($scores);
$avg = array_sum($scores) / count($scores);
$grade = match (true) {
    $avg >= 90 => 'A',
    $avg >= 80 => 'B',
    $avg >= 70 => 'C',
    default => 'D',
};
$scoreList = implode(',', $scores);
$note = htmlspecialchars($req['note'] ?? '', ENT_QUOTES, 'UTF-8');
$body = sprintf(
    'Hello %s: avg=%.1f grade=%s scores=%s note=%s',
    $req['name'],
    $avg,
    $grade,
    $scoreList,
    $note
);
$resp = [
    'ok' => true,
    'grade' => $grade,
    'avg' => round($avg, 1),
    'body' => $body,
    'n' => count($scores),
];
$json = json_encode($resp);
echo $json, "\n";
echo 'checksum=', strlen($json), ':', sprintf('%u', crc32($json)), "\n";
