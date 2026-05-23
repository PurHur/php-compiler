--TEST--
AOT: void helper early return after rejectContactInput (404 branch, #834, #764)
--ENV--
REQUEST_METHOD=POST
--FILE--
<?php

declare(strict_types=1);

function contact_name_is_valid(): bool
{
    if (!isset($_REQUEST['name'])) {
        return false;
    }
    $name = $_REQUEST['name'];
    if ($name == '') {
        return false;
    }
    if ($name != substr($name, 0, 200)) {
        return false;
    }

    return true;
}

class Router
{
    public function dispatch(string $method, string $route): void
    {
        if ('POST' !== $method || 'contact' !== $route) {
            return;
        }
        if (!contact_name_is_valid()) {
            $this->rejectContactInput();

            return;
        }
        echo 'thanks:', $_REQUEST['name'], "\n";
    }

    private function rejectContactInput(): void
    {
        http_response_code(400);
        echo "Invalid name\n";
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
(new Router())->dispatch($method, 'contact');
--EXPECT--
Status: 400
Invalid name
--EXPECT_EXIT--
0
