<?php
declare(strict_types=1);

namespace Naf\Support;

use Psr\Http\Message\ServerRequestInterface;

class RequestParameter
{
    protected array $data = [];

    public function __construct(ServerRequestInterface $request)
    {
        $this->data = $this->parse($request);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->data)) {
            return $this->data[$key];
        }

        $segments = explode('.', $key);
        $value = $this->data;

        foreach ($segments as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return $default;
            }
        }

        return $value;
    }

    public function all(): array
    {
        return $this->data;
    }

    protected function parse(ServerRequestInterface $request): array
    {
        $input = [];

        // 1. Query Params (GET)
        $input = array_merge($input, $request->getQueryParams());

        // 2. Parsed Body (POST, PUT, PATCH, DELETE with form data)
        $body = $request->getParsedBody();
        if (is_array($body)) {
            $input = array_merge($input, $body);
        }

        // 3. JSON Body
        if (empty($body) && str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
            $raw = (string) $request->getBody();
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $input = array_merge($input, $json);
            }
        }

        return $input;
    }
}

