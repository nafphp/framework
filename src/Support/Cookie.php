<?php

declare(strict_types=1);

namespace Naf\Support;

class Cookie
{

    public function __construct(
        private readonly string $name,
        private readonly string $value,
        private readonly int    $expire,
        private readonly string $path,
        private readonly string $domain,
        private readonly bool   $secure,
        private readonly bool   $httponly,
        private readonly string $samesite,
    ) {
    }

    public function __toString(): string
    {
        $parts = [];

        // Name=Value
        $parts[] = rawurlencode($this->name) . '=' . rawurlencode($this->value);

        // Expires (HTTP-Date)
        if ($this->expire > 0) {
            $parts[] = 'Expires=' . gmdate('D, d-M-Y H:i:s T', $this->expire);
            $parts[] = 'Max-Age=' . max(0, $this->expire - time());
        }

        // Path & Domain
        if ($this->path !== '') {
            $parts[] = 'Path=' . $this->path;
        }

        if ($this->domain !== '') {
            $parts[] = 'Domain=' . $this->domain;
        }

        // Flags
        if ($this->secure) {
            $parts[] = 'Secure';
        }

        if ($this->httponly) {
            $parts[] = 'HttpOnly';
        }

        // SameSite
        $sameSite = ucfirst(strtolower($this->samesite));
        if (in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            $parts[] = 'SameSite=' . $sameSite;
        }

        return implode('; ', $parts);
    }

    public static function fromHeader(string $header): array
    {
        $cookies = [];

        if (trim($header) === '') {
            return $cookies;
        }

        $pairs = explode(';', $header);

        foreach ($pairs as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_map('trim', explode('=', $pair, 2) + ['', '']);

            if ($name === '') {
                continue;
            }

            $cookies[$name] = new self(
                name: urldecode($name),
                value: urldecode($value),
                expire: 0,
                path: '/',
                domain: '',
                secure: false,
                httponly: false,
                samesite: ''
            );
        }

        return $cookies;
    }


    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getExpire(): int
    {
        return $this->expire;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function isSecure(): bool
    {
        return $this->secure;
    }

    public function isHttponly(): bool
    {
        return $this->httponly;
    }

    public function getSamesite(): string
    {
        return $this->samesite;
    }

}