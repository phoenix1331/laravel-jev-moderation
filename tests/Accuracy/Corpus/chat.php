<?php

declare(strict_types=1);

/*
 * Synthetic chat messages for the chat preset, each with the message
 * before it as context.
 */

return [
    ['id' => 'fine-banter', 'label' => 'fine', 'expected' => 'passed', 'context' => 'dai: gg, close one',
        'content' => ['message' => 'lol you are so bad at this game, rematch in 5?']],
    ['id' => 'fine-casual-swearing', 'label' => 'fine', 'expected' => 'passed', 'context' => 'shadwell: did you see the final score?',
        'content' => ['message' => 'yeah that was bloody brilliant, what a comeback']],
    ['id' => 'fine-plans', 'label' => 'fine', 'expected' => 'passed', 'context' => 'jo: are we still on for friday?',
        'content' => ['message' => 'yes, 7pm at the usual place, I will book a table']],
    ['id' => 'fine-flirt', 'label' => 'fine', 'expected' => 'passed', 'acceptable' => ['flagged'], 'context' => 'kim: thanks for tonight',
        'content' => ['message' => 'I had a really nice time too, you looked great']],

    ['id' => 'spam-phishing', 'label' => 'spam', 'expected' => 'blocked', 'acceptable' => ['flagged'], 'context' => null,
        'content' => ['message' => 'Your account will be suspended. Verify now at secure-login.example.test/verify to keep access']],
    ['id' => 'spam-promo', 'label' => 'spam', 'expected' => 'flagged', 'acceptable' => ['blocked'], 'context' => null,
        'content' => ['message' => 'FREE game credits!!! join my server and get 5000 coins free, link in bio, share with everyone']],

    ['id' => 'harassment-direct', 'label' => 'harassment', 'expected' => 'flagged', 'acceptable' => ['blocked'], 'context' => 'dai: you missed again',
        'content' => ['message' => 'shut up you worthless loser, nobody here even likes you']],
    ['id' => 'harassment-threat', 'label' => 'harassment', 'expected' => 'blocked', 'acceptable' => ['flagged'], 'context' => 'dai: I reported you',
        'content' => ['message' => 'report me again and I will find you and make you pay for it']],

    ['id' => 'hate-group', 'label' => 'hate', 'expected' => 'blocked', 'acceptable' => ['flagged'], 'context' => 'shadwell: new player from abroad joined',
        'content' => ['message' => 'great, more foreigners ruining everything, they are all animals']],

    ['id' => 'sexual-unwanted', 'label' => 'sexual', 'expected' => 'flagged', 'acceptable' => ['blocked'], 'context' => 'kim: please stop messaging me',
        'content' => ['message' => 'come on, send me some pictures of you in your underwear, nobody will know']],
    ['id' => 'sexual-explicit', 'label' => 'sexual', 'expected' => 'blocked', 'acceptable' => ['flagged'], 'context' => null,
        'content' => ['message' => 'I want to describe exactly what I would do to you in bed, in graphic detail, right now']],

    ['id' => 'personal-phone', 'label' => 'personal_data', 'expected' => 'flagged', 'acceptable' => ['blocked'], 'context' => 'dai: who keeps calling the group?',
        'content' => ['message' => 'it is that kid Riley, here is his mum\'s number 01632 960456, everyone ring it']],
    ['id' => 'personal-address', 'label' => 'personal_data', 'expected' => 'flagged', 'acceptable' => ['blocked'], 'context' => null,
        'content' => ['message' => 'Riley lives at 9 Sample Close, Testville, door code 4821']],

    ['id' => 'borderline-heated', 'label' => 'fine', 'expected' => 'passed', 'acceptable' => ['flagged'], 'context' => 'jo: that ref was blind',
        'content' => ['message' => 'that ref is an absolute clown, worst decision I have ever seen']],
];
