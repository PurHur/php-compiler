--TEST--
AOT: switch arm passes superglobal-derived arg to private method (MiniWebApp POST contact, #764)
--ENV--
REQUEST_METHOD=POST
QUERY_STRING=route=contact&name=Ada
--FILE--
<?php
class Router
{
    public function dispatch(string $method, string $route): void
    {
        switch ($method) {
            case 'POST':
                if ('contact' === $route) {
                    $this->renderContactThankYou((string) $_REQUEST['name']);
                    return;
                }
                break;
            default:
                break;
        }
        echo "skip\n";
    }

    private function renderContactThankYou($contactName): void
    {
        echo 'thanks:', $contactName, "\n";
    }
}

(new Router())->dispatch('POST', 'contact');
--EXPECT--
thanks:Ada
