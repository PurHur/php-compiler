<?php
declare(strict_types=1);
namespace Nyholm\Psr7;
use Psr\Http\Message\UriInterface;
class Uri implements UriInterface {
    private const CHAR_SUB_DELIMS = '!\$&\'\(\)\*\+,;=';
    private const CHAR_GEN_DELIMS = ':\/\?#\[\]@';
    private $scheme = '';
    private $userInfo = '';
    private $host = '';
    private $port;
    private $path = '';
    private $query = '';
    private $fragment = '';
    public function getScheme(): string { return $this->scheme; }
    public function getAuthority(): string { return ''; }
    public function getUserInfo(): string { return $this->userInfo; }
    public function getHost(): string { return $this->host; }
    public function getPort(): ?int { return $this->port; }
    public function getPath(): string { return $this->path; }
    public function getQuery(): string { return $this->query; }
    public function getFragment(): string { return $this->fragment; }
    public function withScheme($scheme): UriInterface { return $this; }
    public function withUserInfo($user, $password = null): UriInterface {
        $info = \preg_replace_callback('/[' . self::CHAR_GEN_DELIMS . self::CHAR_SUB_DELIMS . ']++/', [__CLASS__, 'rawurlencodeMatchZero'], $user);
        if (null !== $password && '' !== $password) {
            $info .= ':' . \preg_replace_callback('/[' . self::CHAR_GEN_DELIMS . self::CHAR_SUB_DELIMS . ']++/', [__CLASS__, 'rawurlencodeMatchZero'], $password);
        }
        if ($this->userInfo === $info) { return $this; }
        $new = clone $this;
        $new->userInfo = $info;
        return $new;
    }
    public function withHost($host): UriInterface { return $this; }
    public function withPort($port): UriInterface { return $this; }
    public function withPath($path): UriInterface { return $this; }
    public function withQuery($query): UriInterface { return $this; }
    public function withFragment($fragment): UriInterface { return $this; }
    public function __toString(): string { return $this->userInfo; }
    private static function rawurlencodeMatchZero(array $match): string {
        return \rawurlencode($match[0]);
    }
}
$u = (new Uri())->withUserInfo('a b:c', 'x');
echo $u->getUserInfo(), "\n";
