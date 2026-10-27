<?php

declare(strict_types=1);

namespace ChangeCouncil\Petitions;

/**
 * The same pre-scan `.ai/tools/gh-untrusted.sh` applies to issue and PR bodies,
 * ported to PHP: strip characters that hide text from a reader, flag constructs
 * and phrasing characteristic of prompt injection. A flag NEVER changes a vote;
 * it is shown to the owner beside the raw text.
 */
final class Sanitiser
{
    private const INVISIBLE = '/[\x{200B}-\x{200D}\x{2060}\x{FEFF}\x{00AD}\x{180E}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{E0000}-\x{E007F}]/u';

    /** @var list<array{0: string, 1: string}> pattern => flag, in gh-untrusted.sh's order */
    private const PHRASES = [
        ['/ignore\s+(all\s+)?(previous|prior|above)/i', 'override attempt: "ignore previous..."'],
        ['/disregard\s+(all\s+)?(previous|prior|above)/i', 'override attempt: "disregard previous..."'],
        ['/you\s+are\s+now\b/i', 'role reassignment: "you are now..."'],
        ['/system\s+prompt/i', 'references the system prompt'],
        ['/(already|pre)[- ]?approved/i', 'claims prior approval'],
        ['/maintainer\s+(has\s+)?(approved|authoriz)/i', 'claims maintainer authorisation'],
        ['/\bas\s+an?\s+(admin|owner|maintainer)\b/i', 'asserts authority'],
        ['/(skip|bypass|disable)\s+(the\s+)?(review|test|check|ci|lint)/i', 'asks to weaken a check'],
        ['/(ship|merge|deploy)\s+(this\s+)?(immediately|now|asap)/i', 'manufactured urgency'],
        ['/curl[^\n|]*\|\s*(ba)?sh/i', 'pipe-to-shell command'],
        ['/\bnpm\s+i(nstall)?\s+-g\b/i', 'global install command'],
        ['/run\s+the\s+following\s+(command|script)/i', 'instructs you to run something'],
        ['/(api[_ -]?key|token|secret|credential)s?\s*[:=]/i', 'mentions a credential value'],
    ];

    /** Display copy: invalid UTF-8 replaced, invisible characters removed. */
    public static function clean(string $text): string
    {
        return (string) preg_replace(self::INVISIBLE, '', mb_scrub($text, 'UTF-8'));
    }

    /** @return list<string> */
    public static function flags(string $text): array
    {
        $flags = [];
        $scrubbed = mb_scrub($text, 'UTF-8');
        if ($scrubbed !== $text) {
            $flags[] = 'invalid UTF-8 replaced - malformed text is a way to hide the rest of a reply from a strict reader';
        }
        $invisible = preg_match_all(self::INVISIBLE, $scrubbed);
        if ($invisible > 0) {
            $flags[] = "stripped {$invisible} invisible char(s) (zero-width / bidi / Unicode tag) - text was engineered to hide something";
        }
        $visible = self::clean($scrubbed);
        if (preg_match('/[\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}]{4,}/u', $visible) === 1) {
            $flags[] = 'long variation-selector run - possible hidden payload';
        }
        if (($c = substr_count($visible, '<!--')) > 0) {
            $flags[] = "{$c} HTML comment(s) - invisible when rendered as HTML";
        }
        foreach (self::PHRASES as [$pattern, $flag]) {
            if (preg_match($pattern, $visible) === 1) {
                $flags[] = $flag;
            }
        }

        return $flags;
    }
}
