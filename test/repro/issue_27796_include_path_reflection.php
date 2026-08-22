<?php
/**
 * #27796 — get_include_path/set_include_path Reflection return string|false
 * php-src: ext/standard/basic_functions.stub.php
 *
 *   ./script/docker-exec.sh -- bash -lc 'php bin/vm.php test/repro/issue_27796_include_path_reflection.php'
 */
foreach (['get_include_path', 'set_include_path'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' return=', $r->hasReturnType() ? (string) $r->getReturnType() : '<none>', "\n";
}
$r = new ReflectionFunction('set_include_path');
foreach ($r->getParameters() as $p) {
    echo 'param=$', $p->getName(), ':', $p->hasType() ? (string) $p->getType() : 'none', "\n";
}
