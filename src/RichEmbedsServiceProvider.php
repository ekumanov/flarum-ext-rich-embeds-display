<?php

namespace Ekumanov\RichEmbedsDisplay;

use Ekumanov\RichEmbedsDisplay\Http\CurlRequestExecutor;
use Ekumanov\RichEmbedsDisplay\Http\DefaultIpFilter;
use Ekumanov\RichEmbedsDisplay\Http\DnsResolver;
use Ekumanov\RichEmbedsDisplay\Http\IpFilter;
use Ekumanov\RichEmbedsDisplay\Http\RequestExecutor;
use Ekumanov\RichEmbedsDisplay\Http\Resolver;
use Ekumanov\RichEmbedsDisplay\Http\SafeHttpClient;
use Ekumanov\RichEmbedsDisplay\Http\UrlValidator;
use Ekumanov\RichEmbedsDisplay\LocalDiscussion\LocalDiscussionResolver;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Binds the SSRF-safe HTTP stack so SafeHttpClient (and its dependencies) can
 * be resolved from the container by jobs and listeners without each callsite
 * having to build the wiring by hand.
 */
class RichEmbedsServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(UrlValidator::class, fn () => new UrlValidator());
        $this->container->singleton(Resolver::class, fn () => new DnsResolver());
        $this->container->singleton(IpFilter::class, fn () => new DefaultIpFilter());

        $this->container->singleton(RequestExecutor::class, fn () => new CurlRequestExecutor(
            connectTimeoutSec: 5,
            totalTimeoutSec: 10,
            maxBytes: 2 * 1024 * 1024,
            // Many sites (and Cloudflare in front of them) refuse non-browser UAs
            // outright. We pose as a recent Chrome — same shape as what facebookexternalhit,
            // Slack, Discord etc. send, just without their bot identifier so we
            // don't get treated as one. The "RichEmbeds" suffix is for op visibility
            // in source-site logs; CF challenges accept it.
            userAgent: 'Mozilla/5.0 (compatible; PianoClack-RichEmbeds/1.0) Chrome/126.0.0.0',
        ));

        $this->container->singleton(SafeHttpClient::class, fn ($c) => new SafeHttpClient(
            urlValidator: $c->make(UrlValidator::class),
            resolver: $c->make(Resolver::class),
            ipFilter: $c->make(IpFilter::class),
            executor: $c->make(RequestExecutor::class),
            maxRedirects: 5,
        ));

        // Self-link short-circuit. Base URL is computed once from Flarum's
        // Config; the resolver compares posted URLs' host+path against it
        // and looks the discussion up locally if it matches.
        $this->container->singleton(LocalDiscussionResolver::class, fn ($c) => new LocalDiscussionResolver(
            forumBaseUrl: (string) $c->make(Config::class)->url(),
            settings: $c->make(SettingsRepositoryInterface::class),
        ));
    }
}
