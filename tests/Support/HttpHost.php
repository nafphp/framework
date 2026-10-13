<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/** Disposable real HTTP host, also usable after a --no-dev installation. */
final class HttpHost
{
    private mixed $process = null;

    private int $port;

    public function __construct(private readonly string $root)
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new RuntimeException($error, $errno);
        }
        $address = stream_socket_get_name($socket, false);
        $this->port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($socket);
    }

    public function start(): void
    {
        $command = [PHP_BINARY];
        if (php_ini_loaded_file() === false) {
            $command[] = '-n';
        }
        array_push($command, '-S', '127.0.0.1:' . $this->port, '-t', $this->root . '/public');
        $this->process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $this->root . '/server.log', 'a'], 2 => ['file', $this->root . '/server.log', 'a']], $pipes, $this->root);
        if (!is_resource($this->process)) {
            throw new RuntimeException('Could not start the HTTP host.');
        }
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $error, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            if (!proc_get_status($this->process)['running']) {
                break;
            }
            usleep(20_000);
        }

        throw new RuntimeException('HTTP host did not start: ' . file_get_contents($this->root . '/server.log'));
    }

    /** @return array{status: int, headers: array<string, list<string>>, body: string} */
    public function request(string $method, string $path, array $headers = [], string $body = ''): array
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $error, 5);
        if ($socket === false) {
            throw new RuntimeException($error, $errno);
        }
        stream_set_timeout($socket, 5);
        $wire = $method . ' ' . $path . " HTTP/1.1\r\nHost: 127.0.0.1:" . $this->port . "\r\nConnection: close\r\n";
        foreach ($headers as $name => $value) {
            $wire .= $name . ': ' . $value . "\r\n";
        }
        $wire .= 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body;
        fwrite($socket, $wire);
        $raw = stream_get_contents($socket);
        $timedOut = stream_get_meta_data($socket)['timed_out'];
        fclose($socket);
        if ($timedOut || $raw === false || !str_contains($raw, "\r\n\r\n")) {
            throw new RuntimeException('Incomplete HTTP response.');
        }
        [$head, $body] = explode("\r\n\r\n", $raw, 2);
        $lines = explode("\r\n", $head);
        preg_match('/^HTTP\/\S+ (\d{3})/', array_shift($lines), $status);
        $responseHeaders = [];
        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2);
            $responseHeaders[strtolower($name)][] = trim($value);
        }

        return ['status' => (int) $status[1], 'headers' => $responseHeaders, 'body' => $body];
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }

    public static function remove(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        foreach (new \FilesystemIterator($root) as $file) {
            if ($file->isDir() && !$file->isLink()) {
                self::remove($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($root);
    }

    public static function expect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
