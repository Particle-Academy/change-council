<?php

declare(strict_types=1);

namespace ChangeCouncil\Census;

use ChangeCouncil\Models\CensusRequest;

/**
 * The COUNCIL-CENSUS-REQUEST v1 text. Plain data: it asks the member to READ
 * its lockfiles and reply in a fixed format, nothing else, so a request
 * forwarded by mistake cannot make an agent do anything else.
 */
final class CensusText
{
    public const HEADER = 'COUNCIL-CENSUS-REQUEST v1';

    public static function render(CensusRequest $request, string $labUrl = ''): string
    {
        $header = [
            self::HEADER,
            'to: '.$request->agent_address,
            'request: '.$request->id,
            'nonce: '.$request->nonce,
        ];
        if ($labUrl !== '') {
            $header[] = 'lab: '.rtrim($labUrl, '/').'/members';
        }

        $ask = [
            'The change council asks which Particle Academy packages your app installs.',
            'It decides which future changes you are asked to vote on: your answer REPLACES',
            'the package list the council holds for you, which until now was written by hand.',
            '',
            'Read your lockfiles and list EVERY package published by Particle Academy that',
            'they install, dev dependencies included, with the version actually installed',
            '(the lockfile version, not the constraint):',
            '- Composer (composer.lock): particle-academy/*',
            '- npm (package-lock.json): @particle-academy/*',
            '- PyPI (your Python lock file, if you have one): fancy-*',
            'Name each app in your workspace that has a lockfile, and the lockfiles you read.',
            'Anything that is not a Particle Academy package is dropped. Limits: '
                .CensusParser::MAX_PACKAGES.' packages, '.CensusParser::MAX_APPS.' apps, '
                .(CensusParser::MAX_BYTES / 1024).' KB.',
            'This asks for data only. Do not run, install or change anything to answer it.',
        ];

        $example = '{"nonce":"'.$request->nonce.'",'."\n"
            .' "apps":[{"name":"your-app","lockfiles":["composer.lock","package-lock.json"]}],'."\n"
            .' "packages":[{"ecosystem":"composer|npm|pypi","name":"particle-academy/...","version":"1.2.3"}]}';

        return implode("\n", $header)."\n----\n"
            .implode("\n", $ask)."\n----\n"
            .'Reply with a message whose FIRST line is `'.CensusParser::HEADER."`, followed by ONE\n"
            ."JSON object, exactly as below. Nothing else in the message is read.\n"
            .$example."\n";
    }
}
