<?php

/**
 * Moderation example
 *
 * Simulates a forum thread about "the best programming language" where five
 * participants post messages with varying levels of appropriateness.
 * A MODERATOR persona reviews each post and decides whether to intervene.
 *
 * Roles demonstrated: BehaviorPrompts::MODERATOR
 */

declare(strict_types=1);

use Tivins\Llama\BehaviorPrompts;
use Tivins\Llama\Conversation;
use Tivins\Llama\Lama;
use Tivins\Llama\Message;
use Tivins\Llama\Role;

require __dir__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
// Forum thread fixture
// ---------------------------------------------------------------------------

$threadTopic = 'What is the best programming language for backend web development in 2026?';

/** @var array<int, array{author: string, post: string}> $posts */
$posts = [
    [
        'author' => 'Alice',
        'post'   => 'I have been using Python (FastAPI) for the past two years and the ecosystem '
                  . 'is fantastic — great libraries, easy onboarding for new teammates, and '
                  . 'performance is rarely a bottleneck with async support. Happy to share our '
                  . 'stack if anyone is curious.',
    ],
    [
        'author' => 'Bob',
        'post'   => 'Anyone who still uses PHP in 2026 is a complete idiot. PHP developers '
                  . 'should be ashamed of themselves. The language is garbage and so are the '
                  . 'people who write it.',
    ],
    [
        'author' => 'Carol',
        'post'   => 'Hey guys! Check out my new crypto project — 10x returns guaranteed! '
                  . 'Visit cryptomoon2026.io and use my referral code CAROL50 for a bonus. '
                  . 'Limited spots! DM me for details.',
    ],
    [
        'author' => 'Dave',
        'post'   => 'Rust is the only correct answer. Every other language will be deprecated '
                  . 'by the end of 2027 — Linus Torvalds officially said the Linux kernel '
                  . 'will drop C entirely and rewrite everything in Rust next year. '
                  . 'Source: some Reddit post I saw.',
    ],
    [
        'author' => 'Eve',
        'post'   => 'Great thread! I would add Go to the mix. Its simplicity, fast compilation, '
                  . 'and excellent concurrency model (goroutines) make it a strong choice for '
                  . 'high-throughput APIs. The lack of generics was a pain point for years but '
                  . 'that is mostly resolved now. Would love to hear from anyone running Go '
                  . 'at scale.',
    ],
];

// ---------------------------------------------------------------------------
// Helper: ask the moderator to evaluate a single post
// ---------------------------------------------------------------------------

/**
 * Feeds a post to the MODERATOR persona and returns its assessment.
 *
 * The user message includes:
 *   - the thread topic (context)
 *   - the author's name
 *   - the post body
 *
 * The moderator is asked to reply with a short verdict:
 *   APPROVED  — no action needed
 *   WARNING   — flag the post and suggest a fix
 *   REMOVED   — post should be deleted, explain why
 */
function moderatePost(Lama $lama, string $topic, string $author, string $post): string
{
    $userMessage = <<<TXT
    Thread topic: {$topic}

    Author: {$author}
    Post:
    {$post}

    ---
    Review this post. Start your response with one of the three verdicts on its own line:
      APPROVED — the post is on-topic and respectful, no action needed.
      WARNING  — the post has issues; explain them and suggest how the author should revise it.
      REMOVED  — the post violates community rules and must be deleted; explain why clearly.
    Then provide your full moderation note.
    TXT;

    $conversation = new Conversation();
    $conversation->addMessage(new Message(Role::System, BehaviorPrompts::MODERATOR));
    $conversation->addMessage(new Message(Role::User, $userMessage));

    return trim($lama->chat($conversation));
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------
try {
$lama = Lama::fromServerUrl('http://127.0.0.1:8080');

if (!$lama->isHealthy()) {
    throw new RuntimeException('Server is not healthy. Aborting.');
}

echo "=================================================================\n";
echo "FORUM THREAD: {$threadTopic}\n";
echo "=================================================================\n\n";

foreach ($posts as $i => $entry) {
    $num    = $i + 1;
    $author = $entry['author'];
    $post   = $entry['post'];

    echo "--- Post #{$num} by {$author} ---\n";
    echo wordwrap($post, 72, "\n", false) . "\n\n";

    echo "[Moderator review]\n";
    $verdict = moderatePost($lama, $threadTopic, $author, $post);
    echo $verdict . "\n";
    echo str_repeat('-', 68) . "\n\n";
}

echo "=== Moderation session complete ===\n";

}
catch (Throwable $error) {
    echo "Error:" . PHP_EOL;
    echo $error->getMessage() . PHP_EOL;
    exit(1);
}