<?php

declare(strict_types=1);

namespace CorreiosSeller\Correios;

final class Credentials
{
    public function __construct(
        public string $username,
        public string $password,
        public string $postingCard = '',
        public string $adminCode = ''
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->username !== '' && $this->password !== '';
    }

    public function hasPostingCard(): bool
    {
        return $this->postingCard !== '';
    }

    public function cacheKey(): string
    {
        return md5($this->username . '|' . $this->password . '|' . $this->postingCard . '|' . $this->adminCode);
    }
}
