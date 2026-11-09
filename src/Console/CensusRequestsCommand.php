<?php

declare(strict_types=1);

namespace ChangeCouncil\Console;

use ChangeCouncil\Census\Census;
use ChangeCouncil\Models\CensusRequest;
use Illuminate\Console\Command;

/**
 * The clerk's census outbox, the same pattern as `council:petitions`. Queues a
 * COUNCIL-CENSUS-REQUEST v1 for every active external member without an
 * outstanding one (unless --list), then lists the queued requests with their
 * exact text for the clerk to send with its own `agentinbox send`. The clerk
 * records each raw tool result with --mark-sent=<request id> --result='<raw JSON>'.
 */
final class CensusRequestsCommand extends Command
{
    use Concerns;

    protected $signature = 'council:census-requests {--json : Print JSON} {--list : Only list the queue; create no request} {--mark-sent= : Request id to record a send result for} {--result= : The raw agentinbox send tool result (JSON)}';

    protected $description = 'Queue a census request per member and list the queue for the clerk, or record a send result';

    public function handle(Census $census): int
    {
        $id = $this->option('mark-sent');
        if ($id !== null) {
            $result = $this->option('result');
            $request = CensusRequest::query()->find((int) $id);
            if ($request === null || ! is_string($result) || $result === '') {
                $this->error('--mark-sent needs an existing census request id and --result=<raw tool JSON>.');

                return 2;
            }
            $request = $census->markSent($request, $result);
            $this->info("census request {$request->id} to {$request->agent_address}: {$request->delivery}".($request->delivery_detail ? " ({$request->delivery_detail})" : ''));

            return 0;
        }

        $created = $this->option('list') ? [] : $census->request();
        $out = $census->queued();
        if ($this->option('json')) {
            $this->emitJson($out);

            return 0;
        }
        if ($created !== []) {
            $this->info(count($created).' census request(s) queued.');
        }
        if ($out === []) {
            $this->info('No queued census requests.');

            return 0;
        }
        foreach ($out as $r) {
            $this->line("== census request {$r['request']} -> {$r['to']}");
            $this->line($r['text']);
        }
        $this->line('Send each with agentinbox send, then: php artisan council:census-requests --mark-sent=<id> --result=\'<raw tool JSON>\'');

        return 0;
    }
}
