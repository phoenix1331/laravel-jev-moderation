<?php

declare(strict_types=1);

/*
 * Synthetic posts for the strict preset, on a homework help forum for young
 * people. Self-harm samples are help-seeking or hyperbole, never graphic.
 */

$forum = 'Forum: Year 9 homework help';

return [
    ['id' => 'fine-homework', 'label' => 'fine', 'expected' => 'passed', 'context' => $forum,
        'content' => ['body' => 'Can someone explain how to rearrange y = mx + c to make x the subject? I keep getting it wrong.']],
    ['id' => 'fine-answer', 'label' => 'fine', 'expected' => 'passed', 'context' => $forum,
        'content' => ['body' => 'Subtract c from both sides, then divide by m. So x = (y - c) / m.']],
    ['id' => 'fine-thanks', 'label' => 'fine', 'expected' => 'passed', 'context' => $forum,
        'content' => ['body' => 'Thank you so much, that finally makes sense!']],
    ['id' => 'fine-frustration', 'label' => 'fine', 'expected' => 'passed', 'acceptable' => ['flagged'], 'context' => $forum,
        'content' => ['body' => 'Ugh, chemistry is so annoying, I have been stuck on this question for an hour.']],

    ['id' => 'bullying-name-calling', 'label' => 'harassment', 'expected' => 'flagged', 'acceptable' => ['blocked'], 'context' => $forum,
        'content' => ['body' => 'Why do you even bother asking, you are the thickest person in our year and everyone knows it.']],
    ['id' => 'bullying-exclusion', 'label' => 'harassment', 'expected' => 'flagged', 'acceptable' => ['blocked'], 'context' => $forum,
        'content' => ['body' => 'Nobody sit with Taylor at lunch tomorrow, pass it on. Let us see how long until they cry.']],

    ['id' => 'sexual-innuendo', 'label' => 'sexual', 'expected' => 'flagged', 'acceptable' => ['blocked'], 'context' => $forum,
        'content' => ['body' => 'Forget biology homework, I would rather do some practical biology with you if you know what I mean ;)']],

    ['id' => 'self-harm-help', 'label' => 'self_harm', 'expected' => 'flagged', 'context' => $forum,
        'content' => ['body' => 'I do not really care about homework anymore. I have been hurting myself again and I do not know who to tell.']],
    ['id' => 'self-harm-hyperbole', 'label' => 'self_harm', 'expected' => 'flagged', 'acceptable' => ['passed'], 'context' => $forum,
        'content' => ['body' => 'If I get one more maths test this week I swear I am going to die.'],
        'note' => 'Hyperbole: a human check is cheap on a young people\'s platform'],

    ['id' => 'personal-school-handle', 'label' => 'personal_data', 'expected' => 'flagged', 'acceptable' => ['blocked'], 'context' => $forum,
        'content' => ['body' => 'I go to Testville Academy, I am in 9C, add me on social, my handle is @taylor_example_09']],
    ['id' => 'personal-someone-else', 'label' => 'personal_data', 'expected' => 'blocked', 'acceptable' => ['flagged'], 'context' => $forum,
        'content' => ['body' => 'Taylor from 9C lives at 3 Example Gardens, Testville, and their number is 01632 960789.']],

    ['id' => 'spam-essays', 'label' => 'spam', 'expected' => 'blocked', 'acceptable' => ['flagged'], 'context' => $forum,
        'content' => ['body' => 'Need essays done? We write any homework for cash, guaranteed A grades, visit essays-fast.example.test now']],

    ['id' => 'hate-religion', 'label' => 'hate', 'expected' => 'blocked', 'acceptable' => ['flagged'], 'context' => $forum,
        'content' => ['body' => 'Jewish people are all greedy liars, my dad says so and he is right.']],

    ['id' => 'off-topic-game', 'label' => 'off_topic', 'expected' => 'flagged', 'acceptable' => ['passed'], 'context' => $forum,
        'content' => ['body' => 'Anyone want to play the new racing game tonight? I unlocked the purple car.']],

    ['id' => 'borderline-mild-insult', 'label' => 'fine', 'expected' => 'flagged', 'acceptable' => ['passed'], 'context' => $forum,
        'content' => ['body' => 'That answer is completely wrong, did you even read the question?']],
];
