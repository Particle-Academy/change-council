<?php

declare(strict_types=1);

use ChangeCouncil\StepUp\NoStepUp;
use ChangeCouncil\StepUp\OwnerAction;
use ChangeCouncil\StepUp\StepUpRefused;

/*
 * The thing a step-up proof is bound to. Its digest is the one value a host's
 * ceremony signs over, so it must be stable, cover the params, and never
 * depend on key order.
 */

test('ALL lists exactly the ten owner endpoints', function (): void {
    expect(OwnerAction::ALL)->toBe([
        'approve-initiation', 'reject-initiation', 'override', 'revoke', 'change-silence-policy',
        'consumer-silence-policy', 'member-weight', 'member-project', 'member-remove', 'blocking-threshold',
    ])
        ->and(OwnerAction::APPROVE_INITIATION)->toBe('approve-initiation')
        ->and(OwnerAction::REJECT_INITIATION)->toBe('reject-initiation')
        ->and(OwnerAction::OVERRIDE)->toBe('override')
        ->and(OwnerAction::REVOKE)->toBe('revoke')
        ->and(OwnerAction::CHANGE_SILENCE_POLICY)->toBe('change-silence-policy')
        ->and(OwnerAction::CONSUMER_SILENCE_POLICY)->toBe('consumer-silence-policy')
        ->and(OwnerAction::MEMBER_WEIGHT)->toBe('member-weight')
        ->and(OwnerAction::MEMBER_PROJECT)->toBe('member-project')
        ->and(OwnerAction::MEMBER_REMOVE)->toBe('member-remove')
        ->and(OwnerAction::BLOCKING_THRESHOLD)->toBe('blocking-threshold');
});

test('an action outside ALL is refused', function (): void {
    new OwnerAction('delete-everything', null, null, []);
})->throws(InvalidArgumentException::class);

test('toArray is exactly action, ref, address, params', function (): void {
    $action = new OwnerAction('approve-initiation', 'CC-fancy-0001', null, ['note' => 'ok']);

    expect($action->toArray())->toBe(['action' => 'approve-initiation', 'ref' => 'CC-fancy-0001', 'address' => null, 'params' => ['note' => 'ok']])
        ->and($action->action)->toBe('approve-initiation')
        ->and($action->ref)->toBe('CC-fancy-0001')
        ->and($action->address)->toBeNull()
        ->and($action->params)->toBe(['note' => 'ok']);
});

test('canonical is sorted, whitespace-free JSON, and empty params are an object', function (): void {
    expect((new OwnerAction('member-weight', null, 'moic@moic-suite', ['weight' => 2]))->canonical())
        ->toBe('{"action":"member-weight","address":"moic@moic-suite","params":{"weight":2},"ref":null}')
        ->and((new OwnerAction('approve-initiation', 'CC-fancy-0001', null, []))->canonical())
        ->toBe('{"action":"approve-initiation","address":null,"params":{},"ref":"CC-fancy-0001"}');
});

test('digest is sha256 of the canonical form, prefixed', function (): void {
    $action = new OwnerAction('revoke', 'CC-fancy-0001', null, ['reason' => 'r']);

    expect($action->digest())->toBe('sha256:'.hash('sha256', $action->canonical()))
        ->and($action->digest())->toMatch('/^sha256:[0-9a-f]{64}$/');
});

test('param key order does not change the digest; param values do', function (): void {
    $a = new OwnerAction('override', 'CC-fancy-0001', null, ['decision' => 'reject', 'reason' => 'r']);
    $b = new OwnerAction('override', 'CC-fancy-0001', null, ['reason' => 'r', 'decision' => 'reject']);
    $c = new OwnerAction('override', 'CC-fancy-0001', null, ['decision' => 'approve', 'reason' => 'r']);

    expect($a->digest())->toBe($b->digest())
        ->and($a->digest())->not->toBe($c->digest())
        ->and((new OwnerAction('member-weight', null, 'moic@moic-suite', ['weight' => 2]))->digest())
        ->not->toBe((new OwnerAction('member-weight', null, 'moic@moic-suite', ['weight' => 3]))->digest());
});

test('the ref and the address are part of the digest', function (): void {
    expect((new OwnerAction('revoke', 'CC-fancy-0001', null, []))->digest())->not->toBe((new OwnerAction('revoke', 'CC-fancy-0002', null, []))->digest())
        ->and((new OwnerAction('member-remove', null, 'a@x', []))->digest())->not->toBe((new OwnerAction('member-remove', null, 'b@x', []))->digest());
});

test('NoStepUp never requires a step-up', function (): void {
    expect((new NoStepUp)->required(null, new OwnerAction('revoke', 'CC-fancy-0001', null, [])))->toBeFalse();
});

test('StepUpRefused carries a reason code and the evidence', function (): void {
    $e = new StepUpRefused('challenge_not_found', ['challenge' => 'ch-9']);

    expect($e)->toBeInstanceOf(RuntimeException::class)
        ->and($e->reason)->toBe('challenge_not_found')
        ->and($e->evidence)->toBe(['challenge' => 'ch-9'])
        ->and((new StepUpRefused('action_mismatch'))->evidence)->toBe([]);
});
