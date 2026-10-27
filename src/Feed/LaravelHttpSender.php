<?php

declare(strict_types=1);

namespace ChangeCouncil\Feed;

use Illuminate\Http\Client\Factory;

/** The default sender, through the host's Laravel HTTP client. */
final class LaravelHttpSender implements HttpSender
{
    public function __construct(private readonly Factory $http) {}

    public function post(string $url, array $headers, string $body): int
    {
        try {
            return $this->http->withHeaders($headers)->withBody($body, 'application/json')->timeout(10)->post($url)->status();
        } catch (\Throwable) {
            return 0;
        }
    }
}
