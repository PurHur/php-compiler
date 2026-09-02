<?php
// #36221 program: custom exception hierarchy
class AppException extends RuntimeException {}
class NotFound extends AppException {}
class Conflict extends AppException {}
function lookup(string $id): string {
    if ($id === 'missing') {
        throw new NotFound('nf:' . $id);
    }
    if ($id === 'busy') {
        throw new Conflict('cf:' . $id);
    }
    return 'found:' . $id;
}
function handle(string $id): string {
    try {
        return lookup($id);
    } catch (NotFound $e) {
        return '404:' . $e->getMessage();
    } catch (AppException $e) {
        return 'app:' . $e->getMessage();
    }
}
$ids = ['ok', 'missing', 'busy'];
$parts = [];
foreach ($ids as $id) {
    $parts[] = handle($id);
}
$out = implode('|', $parts) . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";
