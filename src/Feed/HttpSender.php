<?php

declare(strict_types=1);

namespace ChangeCouncil\Feed;

/** POSTs a body and returns the HTTP status (0 when the request never arrived). */
interface HttpSender
{
    /** @param array<string, string> $headers */
    public function post(string $url, array $headers, string $body): int;
}
