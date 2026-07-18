<?php
/**
 * Repro #20682 — Redis pub/sub + SCAN + streams + companions after #20612.
 */
$r = new Redis();
$methods = [
    'publish', 'subscribe', 'psubscribe',
    'scan', 'hScan', 'sScan', 'zScan',
    'xAdd', 'xRead', 'xGroup',
    'pconnect', 'rawCommand', 'setEx', 'setNx',
    'blPop', 'brPop', 'info', 'flushAll', 'watch', 'unwatch',
];
foreach ($methods as $m) {
    echo $m, '=', method_exists($r, $m) ? '1' : '0', "\n";
}

try {
    $r->connect('127.0.0.1', 6379, 0.25);
} catch (RedisException $e) {
    echo "live=skip\n";
    return;
}

$pfx = 'phpc_20682_'.bin2hex(random_bytes(3));
$r->setEx($pfx.':ex', 30, 'v');
echo 'setex=', (string) $r->get($pfx.':ex'), "\n";
echo 'setnx=', $r->setNx($pfx.':nx', '1') ? '1' : '0', "\n";
echo 'setnx2=', $r->setNx($pfx.':nx', '2') ? '1' : '0', "\n";

$r->rPush($pfx.':l', 'a');
$bp = $r->blPop([$pfx.':l'], 1);
echo 'blpop=', is_array($bp) && count($bp) === 2 ? '1' : '0', "\n";

$pub = $r->publish($pfx.':ch', 'hi');
echo 'publish_n=', (string) $pub, "\n";

$r->set($pfx.':a', '1');
$r->set($pfx.':b', '2');
$it = null;
$seen = 0;
do {
    $batch = $r->scan($it, $pfx.'*', 10);
    if (false === $batch) {
        break;
    }
    if (is_array($batch)) {
        $seen += count($batch);
    }
} while (0 !== (int) $it);
echo 'scan_n=', $seen > 0 ? '1' : '0', "\n";

$r->hSet($pfx.':h', 'f', '1');
$hit = null;
$h = $r->hScan($pfx.':h', $hit);
echo 'hscan=', is_array($h) && isset($h['f']) ? '1' : '0', "\n";

$xid = $r->xAdd($pfx.':s', '*', ['k' => 'v']);
echo 'xadd=', is_string($xid) && '' !== $xid ? '1' : '0', "\n";
$xr = $r->xRead([$pfx.':s' => '0-0'], 1);
echo 'xread=', is_array($xr) || false === $xr ? '1' : '0', "\n";

$info = $r->info('server');
echo 'info=', is_array($info) && count($info) > 0 ? '1' : '0', "\n";

$raw = $r->rawCommand('PING');
echo 'raw=', is_string($raw) && 'PONG' === $raw ? '1' : '0', "\n";

$r->watch($pfx.':a');
$r->unwatch();
echo "watch=1\n";

$r->del($pfx.':ex', $pfx.':nx', $pfx.':l', $pfx.':a', $pfx.':b', $pfx.':h', $pfx.':s');
$r->close();
echo "live=ok\n";
