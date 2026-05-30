--TEST--
property fetch copied to local before array offset (issue #2059; Router resolveAppName)
--FILE--
<?php
class Box
{
    /** @var array<string, string> */
    private array $data;

    /** @param array<string, string> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function label(): string
    {
        $cfg = $this->data;
        if (isset($cfg['name'])) {
            return $cfg['name'];
        }
        return 'default';
    }
}

$box = new Box(['name' => 'MiniWebApp']);
echo $box->label(), "\n";
--EXPECT--
MiniWebApp
