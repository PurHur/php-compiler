<?php
// #36221 program: exceptions crossing closures + finally
class AttemptState {
    public static int $ticks = 0;
}
function attempt(callable $fn): string {
    try {
        return 'ok:' . $fn();
    } catch (InvalidArgumentException $e) {
        return 'ia:' . $e->getMessage();
    } catch (RuntimeException $e) {
        return 'rt:' . $e->getMessage();
    } finally {
        AttemptState::$ticks++;
    }
}
$ok = attempt(static function () { return 'fine'; });
$bad = attempt(static function () {
    throw new InvalidArgumentException('nope');
});
$wrap = static function () {
    return attempt(static function () {
        throw new RuntimeException('deep');
    });
};
$deep = $wrap();
$out = "$ok|$bad|$deep|ticks=" . AttemptState::$ticks . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";
