<?php

declare(strict_types=1);

namespace CorreiosSeller\Support;

final class Logger
{
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    private function log(string $level, string $message, array $context): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $message, [
                'source' => 'correios-seller',
                'context' => $context,
            ]);
        }
    }
}
