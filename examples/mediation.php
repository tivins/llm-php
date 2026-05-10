<?php

/**
 * Mediation example
 *
 * Simulates a project-team stand-off with three parties in conflict over
 * whether to delay a product launch. A MEDIATOR persona receives all
 * statements at once and facilitates understanding between the parties
 * across three rounds:
 *
 *   Round 1 — initial analysis: restate positions, surface interests,
 *             highlight common ground, propose paths forward.
 *   Round 2 — follow-up: one option is removed from the table; the
 *             mediator helps the group stay on course.
 *   Round 3 — the mediator is asked for a concrete, time-boxed decision
 *             process the whole team can commit to.
 *
 * Roles demonstrated: BehaviorPrompts::MEDIATOR
 */

declare(strict_types=1);

use Tivins\Llama\BehaviorPrompts;
use Tivins\Llama\Conversation;
use Tivins\Llama\Lama;
use Tivins\Llama\Message;
use Tivins\Llama\Role;

require __dir__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
// Conflict context and parties
// ---------------------------------------------------------------------------

$conflictContext =
    'A SaaS product team is deciding whether to ship v2.0 on the originally '
    . 'scheduled date (in two weeks) or to postpone the launch by four weeks to '
    . 'address remaining issues. Three stakeholders are in sharp disagreement.';

/** @var array<int, array{name: string, role: string, statement: string}> $parties */
$parties = [
    [
        'name'      => 'Alice',
        'role'      => 'Lead Developer',
        'statement' =>
            'The codebase still has three critical bugs that can cause data loss for users '
            . 'who import CSV files. I have flagged these for two sprints and they keep '
            . 'getting pushed aside. If we ship on schedule and these hit production we will '
            . 'lose customer trust permanently. Four more weeks is the absolute minimum. '
            . 'If the business side cannot accept that, they simply do not respect '
            . 'engineering discipline.',
    ],
    [
        'name'      => 'Bob',
        'role'      => 'Head of Sales',
        'statement' =>
            'We have been promising this release to three enterprise clients for six months. '
            . 'Delaying again will almost certainly cause two of them to sign with a '
            . 'competitor — that is €400 k of ARR at risk. There are always bugs; we ship '
            . 'what we have, fix in a patch, and keep the deals. Engineering is holding the '
            . 'whole company hostage over perfectionism.',
    ],
    [
        'name'      => 'Carol',
        'role'      => 'Head of Product',
        'statement' =>
            'I understand both sides, but neither solution is acceptable. Shipping broken '
            . 'CSV imports will cause support overload and destroy our NPS. Delaying four '
            . 'weeks blows the sales pipeline. What if we launched a controlled beta for '
            . 'the enterprise clients with known limitations disclosed, bought two weeks to '
            . 'patch the critical bugs, then did a full GA release? I have tried to raise '
            . 'this in three separate meetings but Alice and Bob just keep arguing and '
            . 'nobody listens.',
    ],
];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Assembles the multi-party situation into a single mediator prompt.
 *
 * @param array<int, array{name: string, role: string, statement: string}> $parties
 */
function buildMediationRequest(string $context, array $parties): string
{
    $lines   = [];
    $lines[] = "Context: {$context}";
    $lines[] = '';

    foreach ($parties as $i => $party) {
        $n       = $i + 1;
        $lines[] = "Party {$n} — {$party['name']} ({$party['role']}):";
        $lines[] = $party['statement'];
        $lines[] = '';
    }

    $lines[] = '---';
    $lines[] = 'Please mediate this conflict according to your instructions.';

    return implode("\n", $lines);
}

/**
 * Appends a follow-up question to an ongoing mediation conversation,
 * sends it to the model, stores the reply, and returns it.
 */
function askFollowUp(Lama $lama, Conversation $conv, string $question): string
{
    $conv->addMessage(new Message(Role::User, $question));
    $reply = trim($lama->chat($conv));
    $conv->addMessage(new Message(Role::Assistant, $reply));
    return $reply;
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

try {
    $lama = Lama::fromServerUrl('http://127.0.0.1:8080');

    if ($lama->getHealth() !== 'ok') {
        throw new Exception("Server is not healthy. Aborting.");
    }

    echo "=================================================================\n";
    echo "MEDIATION SESSION: Product Launch Conflict\n";
    echo "=================================================================\n\n";

    // -----------------------------------------------------------------------
    // Display each party's raw statement
    // -----------------------------------------------------------------------

    echo "--- Positions of each party ---\n\n";
    foreach ($parties as $party) {
        echo "{$party['name']} ({$party['role']}):\n";
        echo wordwrap($party['statement'], 72, "\n", false) . "\n\n";
    }

    // -----------------------------------------------------------------------
    // Round 1 — full mediation: positions → interests → common ground → paths
    // -----------------------------------------------------------------------

    echo str_repeat('=', 68) . "\n";
    echo "[Round 1 — Mediator: initial analysis]\n";
    echo str_repeat('=', 68) . "\n";

    $conversation = new Conversation();
    $conversation->addMessage(new Message(Role::System, BehaviorPrompts::MEDIATOR));
    $conversation->addMessage(new Message(Role::User, buildMediationRequest($conflictContext, $parties)));

    $round1 = trim($lama->chat($conversation));
    $conversation->addMessage(new Message(Role::Assistant, $round1));

    echo $round1 . "\n";
    echo str_repeat('-', 68) . "\n\n";

    // -----------------------------------------------------------------------
    // Round 2 — follow-up: the controlled-beta option is off the table
    // -----------------------------------------------------------------------

    echo str_repeat('=', 68) . "\n";
    echo "[Round 2 — Mediator: beta option rejected by Bob]\n";
    echo str_repeat('=', 68) . "\n";

    $round2 = askFollowUp(
        $lama,
        $conversation,
        'Bob firmly rejects the controlled-beta idea: the enterprise contracts specify a '
        . '"full general-availability release", so a limited beta would be a contract breach. '
        . 'With that option removed, how do you help the group move forward?',
    );

    echo $round2 . "\n";
    echo str_repeat('-', 68) . "\n\n";

    // -----------------------------------------------------------------------
    // Round 3 — follow-up: ask for a concrete 24-hour decision process
    // -----------------------------------------------------------------------

    echo str_repeat('=', 68) . "\n";
    echo "[Round 3 — Mediator: propose a concrete 24-hour decision process]\n";
    echo str_repeat('=', 68) . "\n";

    $round3 = askFollowUp(
        $lama,
        $conversation,
        'The team agrees to let you design one concrete, time-boxed decision process '
        . 'they can all commit to completing within the next 24 hours. '
        . 'What do you propose, step by step?',
    );

    echo $round3 . "\n";
    echo str_repeat('-', 68) . "\n\n";

    echo "=== Mediation session complete ===\n";

} catch (Throwable $error) {
    echo "Error:" . PHP_EOL;
    echo $error->getMessage() . PHP_EOL;
    exit(1);
}
