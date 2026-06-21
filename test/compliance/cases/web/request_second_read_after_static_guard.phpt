--TEST--
VM: second $_REQUEST read in caller after static guard (#10389, 003-MiniWebApp contact)
--ENV--
REQUEST_METHOD=POST
REQUEST_BODY=name=PostDev
--FILE--
<?php
declare(strict_types=1);

class Router
{
    private static function contactNameIsValid(): bool
    {
        $name = $_REQUEST['name'] ?? '';
        if ($name == '') {
            return false;
        }

        return true;
    }

    public static function run(): void
    {
        if (!self::contactNameIsValid()) {
            echo "invalid\n";
            return;
        }
        $contactName = $_REQUEST['name'] ?? '';
        echo 'Thank you, ', (string) $contactName, "\n";
    }
}

Router::run();
--EXPECT--
Thank you, PostDev
--EXPECT_EXIT--
0
