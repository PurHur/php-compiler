<?php
declare(strict_types=1);

require __DIR__ . '/../../..' . '/vendor/autoload.php';

$parser = (new PhpParser\ParserFactory())->create(PhpParser\ParserFactory::PREFER_PHP7);

$cases = [
    ['name' => 'assign', 'source' => "<?php \$a=1+2;"],
    ['name' => 'function', 'source' => "<?php function add(\$a,\$b){return \$a+\$b;}"],
    ['name' => 'class', 'source' => "<?php class Box{public function get(){return 1;}}"],
    ['name' => 'attrs', 'source' => "<?php #[A(1)] function f(){}"],
    ['name' => 'match', 'source' => "<?php \$x=match(\$n){1=>2,default=>0};"],
    ['name' => 'closure', 'source' => "<?php \$f=fn(\$x)=>\$x+1;"],
    ['name' => 'error', 'source' => "<?php function (", 'expect_error' => true],
];

$pass = 0;
$fail = 0;
$skip = 0;
$failures = [];

foreach ($cases as $case) {
    $expectError = (bool) ($case['expect_error'] ?? false);
    try {
        $ast = $parser->parse($case['source']);
        if (!is_array($ast) || $ast === []) {
            throw new RuntimeException('empty ast');
        }
        if ($expectError) {
            $fail++;
            $failures[] = $case['name'] . ':expected_error_not_thrown';
            continue;
        }
        $pass++;
    } catch (PhpParser\Error $e) {
        if ($expectError) {
            $pass++;
            continue;
        }
        $fail++;
        $failures[] = $case['name'] . ':parse_error';
    } catch (Throwable $e) {
        $fail++;
        $failures[] = $case['name'] . ':runtime_error';
    }
}

echo "SUMMARY pass=$pass fail=$fail skip=$skip total=" . ($pass + $fail + $skip) . "\n";
if ($failures !== []) {
    echo 'FAILURES ' . implode(',', $failures) . "\n";
}
exit($fail === 0 ? 0 : 1);
