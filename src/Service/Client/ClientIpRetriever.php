<?php

namespace App\Service\Client;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\IpUtils;

class ClientIpRetriever
{
    public function getClientIp(Request $request): string
    {
        // The local Cloudflare tunnel reaches the application through Docker's private network.
        $peer = $request->server->getString('REMOTE_ADDR');
        $cfIp = $request->headers->get('CF-Connecting-IP');
        if (IpUtils::checkIp($peer, ['127.0.0.0/8', '::1', '172.16.0.0/12'])
            && filter_var($cfIp, FILTER_VALIDATE_IP)
        ) {
            return $cfIp;
        }

        // Otherwise rely on Symfony's built-in resolver
        return $request->getClientIp() ?? 'unknown';
    }
}
