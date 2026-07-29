<?php
// Issue #23846 / #24533 — session_set_cookie_params Zend stub named params (ext/session/session.stub.php).
$ok = session_set_cookie_params(lifetime_or_options: 3600, path: '/app');
$params = session_get_cookie_params();
$rf = new ReflectionFunction('session_set_cookie_params');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'ok:', $ok ? '1' : '0', PHP_EOL;
echo 'lifetime:', (string) $params['lifetime'], PHP_EOL;
echo 'path:', (string) $params['path'], PHP_EOL;
echo 'params:', implode(',', $names), PHP_EOL;
?>
