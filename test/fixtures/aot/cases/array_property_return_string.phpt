--TEST--
AOT: return typed string from private method (MiniWebApp resolveAppName pattern, issue #58)
--FILE--
<?php
declare(strict_types=1);
class ConfigHolder {
    private function appName(): string {
        return 'AOT';
    }
    public function run(): void {
        echo $this->appName(), "\n";
    }
}
(new ConfigHolder())->run();
--EXPECT--
AOT
--EXPECT_EXIT--
0
