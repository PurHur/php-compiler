<?php

declare(strict_types=1);

if (!method_exists('SplObserver', 'update')) {
    echo "fail: method_exists(SplObserver::update) false\n";
    exit(1);
}

$observerMethods = (new ReflectionClass('SplObserver'))->getMethods();
if ([] === $observerMethods || 'update' !== $observerMethods[0]->getName()) {
    echo 'fail: ReflectionClass(SplObserver)->getMethods() '.var_export(array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        $observerMethods
    ), true)."\n";
    exit(1);
}

foreach (['attach', 'detach', 'notify'] as $method) {
    if (!method_exists('SplSubject', $method)) {
        echo "fail: method_exists(SplSubject::{$method}) false\n";
        exit(1);
    }
}

$subjectMethods = (new ReflectionClass('SplSubject'))->getMethods();
$names = array_map(static fn (ReflectionMethod $m): string => $m->getName(), $subjectMethods);
sort($names);
if (['attach', 'detach', 'notify'] !== $names) {
    echo 'fail: ReflectionClass(SplSubject)->getMethods() '.var_export($names, true)."\n";
    exit(1);
}

echo "ok\n";
