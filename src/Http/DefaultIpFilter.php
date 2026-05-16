<?php

namespace Ekumanov\RichEmbedsDisplay\Http;

/**
 * Production IP filter — delegates to PrivateIpFilter::isPrivate().
 */
final class DefaultIpFilter implements IpFilter
{
    public function isPrivate(string $ip): bool
    {
        return PrivateIpFilter::isPrivate($ip);
    }
}
