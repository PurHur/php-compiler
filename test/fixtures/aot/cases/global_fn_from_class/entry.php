<?php

declare(strict_types=1);

/**
 * MiniWebApp Router::dispatch contact guard pattern (#831, #764).
 *
 * @return bool true when name is non-empty and at most 200 bytes
 */
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
            echo "reject\n";

            return;
        }
        echo 'thanks:', $_REQUEST['name'], "\n";
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
(new Router())->dispatch($method, 'contact');
