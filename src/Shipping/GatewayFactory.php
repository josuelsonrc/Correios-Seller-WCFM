<?php

declare(strict_types=1);

namespace CorreiosSeller\Shipping;

use CorreiosSeller\MelhorEnvio\MelhorEnvioClient;
use CorreiosSeller\MelhorEnvio\MelhorEnvioGateway;
use CorreiosSeller\MelhorEnvio\MelhorEnvioOAuthService;
use CorreiosSeller\MelhorEnvio\MelhorEnvioTokenResolver;
use CorreiosSeller\Repository\QuoteUsageRepository;
use CorreiosSeller\Repository\VendorSettingsRepository;
use CorreiosSeller\Support\Cache;
use CorreiosSeller\Support\Logger;

final class GatewayFactory
{
    public static function createQuoteService(
        VendorSettingsRepository $vendorRepository,
        Cache $cache,
        Logger $logger,
        ?QuoteUsageRepository $usage = null
    ): GatewayQuoteService {
        $oauth = new MelhorEnvioOAuthService($logger);
        $tokenResolver = new MelhorEnvioTokenResolver($vendorRepository, $oauth, $logger);
        $registry = new GatewayRegistry([
            new MelhorEnvioGateway(new MelhorEnvioClient($logger), $tokenResolver),
        ]);

        return new GatewayQuoteService($registry, $cache, $usage ?? new QuoteUsageRepository(), $logger);
    }
}
