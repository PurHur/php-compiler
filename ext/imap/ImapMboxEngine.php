<?php

declare(strict_types=1);

namespace PHPCompiler\ext\imap;

/**
 * Minimal Unix mbox parser for local mailboxes (php-src c-client local path; #3663).
 *
 * Messages are split on {@code From_} separator lines. No network / c-client.
 */
final class ImapMboxEngine
{
    /**
     * @return list<array{raw: string, headers: string, body: string, headerMap: array<string, string>}>
     */
    public static function parseFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Cannot read mailbox file: '.$path);
        }
        $data = file_get_contents($path);
        if (false === $data) {
            throw new \RuntimeException('Cannot read mailbox file: '.$path);
        }

        return self::parseString($data);
    }

    /**
     * @return list<array{raw: string, headers: string, body: string, headerMap: array<string, string>}>
     */
    public static function parseString(string $data): array
    {
        $data = str_replace(["\r\n", "\r"], "\n", $data);
        if ('' === $data) {
            return [];
        }

        $parts = preg_split('/(?=^From )/m', $data, -1, PREG_SPLIT_NO_EMPTY);
        if (false === $parts) {
            return [];
        }

        $messages = [];
        foreach ($parts as $part) {
            $part = rtrim($part, "\n")."\n";
            if (!str_starts_with($part, 'From ')) {
                continue;
            }
            // Drop the From_ envelope line.
            $nl = strpos($part, "\n");
            $rest = false === $nl ? '' : substr($part, $nl + 1);
            $split = strpos($rest, "\n\n");
            if (false === $split) {
                $headers = $rest;
                $body = '';
            } else {
                $headers = substr($rest, 0, $split);
                $body = substr($rest, $split + 2);
            }
            $messages[] = [
                'raw' => $part,
                'headers' => $headers,
                'body' => $body,
                'headerMap' => self::parseHeaders($headers),
            ];
        }

        return $messages;
    }

    /**
     * @return array<string, string>
     */
    public static function parseHeaders(string $headers): array
    {
        $map = [];
        $current = null;
        foreach (explode("\n", $headers) as $line) {
            if ('' === $line) {
                continue;
            }
            if (isset($line[0]) && (' ' === $line[0] || "\t" === $line[0]) && null !== $current) {
                $map[$current] .= ' '.trim($line);
                continue;
            }
            $colon = strpos($line, ':');
            if (false === $colon) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            $map[$name] = $value;
            $current = $name;
        }

        return $map;
    }

    /**
     * @param list<array{raw: string, headers: string, body: string, headerMap: array<string, string>}> $messages
     *
     * @return list<int> 1-based message numbers
     */
    public static function search(array $messages, string $criteria): array
    {
        $criteria = trim($criteria);
        if ('' === $criteria || 0 === strcasecmp($criteria, 'ALL')) {
            $out = [];
            for ($i = 1, $n = \count($messages); $i <= $n; ++$i) {
                $out[] = $i;
            }

            return $out;
        }

        $out = [];
        if (preg_match('/^SUBJECT\s+"([^"]*)"$/i', $criteria, $m)
            || preg_match('/^SUBJECT\s+(\S+)$/i', $criteria, $m)
        ) {
            $needle = strtolower($m[1]);
            foreach ($messages as $i => $msg) {
                $subj = strtolower($msg['headerMap']['subject'] ?? '');
                if (str_contains($subj, $needle)) {
                    $out[] = $i + 1;
                }
            }

            return $out;
        }

        if (preg_match('/^FROM\s+"([^"]*)"$/i', $criteria, $m)
            || preg_match('/^FROM\s+(\S+)$/i', $criteria, $m)
        ) {
            $needle = strtolower($m[1]);
            foreach ($messages as $i => $msg) {
                $from = strtolower($msg['headerMap']['from'] ?? '');
                if (str_contains($from, $needle)) {
                    $out[] = $i + 1;
                }
            }

            return $out;
        }

        // Unsupported criteria → empty (c-client would error; keep v1 quiet).
        return [];
    }
}
