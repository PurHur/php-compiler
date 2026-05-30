<?php

declare(strict_types=1);

namespace PHPCompiler\Web\FastCgi;

/**
 * Assembled FastCGI request (BEGIN_REQUEST + PARAMS + STDIN).
 */
final class Request
{
    public int $requestId = 0;

    public int $role = Record::ROLE_RESPONDER;

    public int $flags = 0;

    public bool $keepConn = false;

    /** @var array<string, string> */
    public array $params = [];

    public string $stdinBody = '';

    /**
     * Read one complete request from a FastCGI stream.
     *
     * @param resource $stream
     *
     * @return self|null null on EOF before BEGIN_REQUEST (keep-alive connection closed)
     */
    public static function readFromStream($stream): ?self
    {
        $req = new self();
        $paramsPayload = '';
        $stdinDone = false;
        $sawBegin = false;

        while (true) {
            $record = Record::readFromStream($stream);
            if (null === $record) {
                break;
            }
            if (0 === $req->requestId) {
                $req->requestId = $record['requestId'];
            }
            switch ($record['type']) {
                case Record::BEGIN_REQUEST:
                    $sawBegin = true;
                    if (strlen($record['content']) >= 2) {
                        $req->role = (ord($record['content'][0]) << 8) | ord($record['content'][1]);
                    }
                    if (strlen($record['content']) >= 3) {
                        $req->flags = ord($record['content'][2]);
                        $req->keepConn = 0 !== ($req->flags & Record::KEEP_CONN);
                    }
                    break;
                case Record::PARAMS:
                    if ('' === $record['content']) {
                        if ('' !== $paramsPayload) {
                            $req->params = ParamsCodec::decode($paramsPayload);
                            $paramsPayload = '';
                        }
                    } else {
                        $paramsPayload .= $record['content'];
                    }
                    break;
                case Record::STDIN:
                    if ('' === $record['content']) {
                        $stdinDone = true;
                    } else {
                        $req->stdinBody .= $record['content'];
                    }
                    break;
                case Record::ABORT_REQUEST:
                    throw new \RuntimeException('FastCGI ABORT_REQUEST received');
                default:
                    break;
            }
            if ($stdinDone) {
                break;
            }
        }

        if (!$sawBegin) {
            return null;
        }
        if ('' !== $paramsPayload) {
            $req->params = ParamsCodec::decode($paramsPayload);
        }

        return $req;
    }

    /**
     * Build request bytes for tests / clients.
     *
     * @param array<string, string> $params
     */
    public static function encode(
        int $requestId,
        array $params,
        string $stdinBody = '',
        int $role = Record::ROLE_RESPONDER,
        int $flags = 0
    ): string {
        $out = Record::encodeBeginRequest($requestId, $role, $flags);
        $encoded = ParamsCodec::encode($params);
        if ('' !== $encoded) {
            $out .= Record::encodeParams($requestId, $encoded);
        }
        $out .= Record::encodeParams($requestId, '');
        if ('' !== $stdinBody) {
            $max = 65535;
            $offset = 0;
            $len = strlen($stdinBody);
            while ($offset < $len) {
                $chunk = substr($stdinBody, $offset, $max);
                $out .= Record::encodeStdin($requestId, $chunk);
                $offset += strlen($chunk);
            }
        }
        $out .= Record::encodeStdin($requestId, '');

        return $out;
    }
}
