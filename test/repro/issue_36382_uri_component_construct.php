<?php
/**
 * #36382 — absolute-path Uri construct without parse_url (Slim NestedJIT safe).
 */
final class UriMini
{
    private string $path = '';
    private string $query = '';
    private string $fragment = '';

    public function __construct(string $uri = '')
    {
        if ('' === $uri) {
            return;
        }
        if (isset($uri[0]) && '/' === $uri[0] && false === strpos($uri, '://')) {
            $end = strlen($uri);
            $hash = strpos($uri, '#');
            if (false !== $hash) {
                $this->fragment = substr($uri, $hash + 1);
                $end = $hash;
            }
            $q = strpos($uri, '?');
            if (false !== $q && $q < $end) {
                $this->query = substr($uri, $q + 1, $end - $q - 1);
                $end = $q;
            }
            $this->path = substr($uri, 0, $end);
        }
    }

    public function getPath(): string
    {
        return $this->path;
    }
}

$u = new UriMini('/hello');
echo 'path='.$u->getPath()."\n";
$u2 = new UriMini('/hello?x=1#f');
echo 'path2='.$u2->getPath()."\n";
echo "OK\n";
