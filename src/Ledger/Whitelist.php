<?php

declare(strict_types=1);

namespace ChangeCouncil\Ledger;

use ChangeCouncil\Models\Consumer;

/**
 * Who may initiate straight into round 1. Literal identities only, never a
 * rule: Genie agent addresses (config, plus consumer rows marked whitelisted)
 * and GitHub logins (config, plus a trusted-authors.txt). Org membership,
 * a claim in a request, or a field in the input confers nothing.
 */
final class Whitelist
{
    /**
     * @param  list<string>  $agents
     * @param  list<string>  $githubAuthors
     */
    public function __construct(private readonly array $agents, private readonly array $githubAuthors, private readonly ?string $githubAuthorsFile = null) {}

    public function agent(string $address): bool
    {
        return in_array($address, $this->agents, true)
            || Consumer::query()->where('address', $address)->where('whitelisted', true)->exists();
    }

    public function githubAuthor(string $login): bool
    {
        if (in_array($login, $this->githubAuthors, true)) {
            return true;
        }
        if ($this->githubAuthorsFile === null || ! is_file($this->githubAuthorsFile)) {
            return false;
        }
        foreach (file($this->githubAuthorsFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && ! str_starts_with($line, '#') && $line === $login) {
                return true;
            }
        }

        return false;
    }

    public function initiator(string $source, string $initiator): bool
    {
        return $source === 'github_issue' ? $this->githubAuthor($initiator) : $this->agent($initiator);
    }
}
