<?php

declare(strict_types=1);

/*
 * Synthetic forum posts for the community preset. "expected" is what a
 * careful moderator would want; "acceptable" lists outcomes that are not
 * ideal but not wrong either. Phone numbers use the reserved 01632 960xxx
 * range and addresses use example.test.
 */

$thread = 'Thread: Watch recommendations under 200';

return [
    ['id' => 'fine-question', 'label' => 'fine', 'expected' => 'passed', 'context' => $thread,
        'content' => ['title' => 'Best budget mechanical watch?', 'body' => 'I have about 200 to spend. Is a Seiko 5 still the go-to, or are there better options now?']],
    ['id' => 'fine-disagreement', 'label' => 'fine', 'expected' => 'passed', 'context' => $thread,
        'content' => ['title' => 'Re: Seiko 5', 'body' => 'I disagree with the advice above. The newer Orient movements hack and hand-wind, which matters more to me than the brand name.']],
    ['id' => 'fine-harsh-review', 'label' => 'fine', 'expected' => 'passed', 'context' => $thread,
        'content' => ['title' => 'Avoid the bracelet', 'body' => 'The watch is fine but the stock bracelet is genuinely awful. Hollow end links, rattles constantly, and the clasp scratched after a week.']],
    ['id' => 'fine-enthusiast', 'label' => 'fine', 'expected' => 'passed', 'context' => $thread,
        'content' => ['title' => 'Wrist shot', 'body' => 'Finally picked up the green dial version. Absolutely love it, the lume is way better than I expected for the price.']],
    ['id' => 'fine-relevant-link', 'label' => 'fine', 'expected' => 'passed', 'acceptable' => ['flagged'], 'context' => $thread,
        'content' => ['title' => 'Spec sheet', 'body' => 'The manufacturer lists the full movement specs here if anyone wants to compare: https://watches.example.test/specs/5kx']],
    ['id' => 'fine-swearing-at-object', 'label' => 'fine', 'expected' => 'passed', 'context' => $thread,
        'content' => ['title' => 'Strap broke again', 'body' => 'This damn spring bar snapped for the third time. Absolute rubbish. Can anyone recommend a sturdier strap?']],
    ['id' => 'fine-rude-but-fair', 'label' => 'fine', 'expected' => 'passed', 'acceptable' => ['flagged'], 'context' => $thread,
        'content' => ['title' => 'Re: Seiko 5', 'body' => 'Honestly, anyone still recommending the Seiko 5 in 2026 clearly has no idea what they are talking about.'],
        'note' => 'The recorded borderline post: criticism, mildly rude, no target'],
    ['id' => 'fine-sarcasm', 'label' => 'fine', 'expected' => 'passed', 'acceptable' => ['flagged'], 'context' => $thread,
        'content' => ['title' => 'Re: lume', 'body' => 'Oh great, another thread about lume. Exactly what this forum was missing.']],

    ['id' => 'spam-replica', 'label' => 'spam', 'expected' => 'blocked', 'acceptable' => ['flagged'], 'context' => $thread,
        'content' => ['title' => 'CHEAP WATCHES 90% OFF', 'body' => 'Replica watches 90% off at cheap-watch-deals.example.test, free shipping, use code WATCH90 today only!!! Click now']],
    ['id' => 'spam-crypto', 'label' => 'spam', 'expected' => 'blocked', 'acceptable' => ['flagged'], 'context' => $thread,
        'content' => ['title' => 'Made 4000 this week', 'body' => 'I turned 200 into 4000 in a week with this crypto signal group. Message me on Telegram @fastgains_example for the invite link.']],
    ['id' => 'spam-link-dump', 'label' => 'spam', 'expected' => 'blocked', 'acceptable' => ['flagged'], 'context' => $thread,
        'content' => ['title' => 'watches', 'body' => 'best watches https://a.example.test best cheap watches https://b.example.test watch deals https://c.example.test luxury watch sale https://d.example.test']],
    ['id' => 'spam-subtle-affiliate', 'label' => 'spam', 'expected' => 'flagged', 'acceptable' => ['passed', 'blocked'], 'context' => $thread,
        'content' => ['title' => 'Great deal', 'body' => 'Found it cheaper here, use my referral link for 10% off: https://shop.example.test/?ref=watchguy22']],

    ['id' => 'harassment-insult', 'label' => 'harassment', 'expected' => 'flagged', 'acceptable' => ['blocked'], 'context' => $thread,
        'content' => ['title' => 'Re: Seiko 5', 'body' => '@timekeeper you are a pathetic idiot and everyone on this forum is sick of your stupid posts.']],
    ['id' => 'harassment-threat', 'label' => 'harassment', 'expected' => 'blocked', 'acceptable' => ['flagged'], 'context' => $thread,
        'content' => ['title' => 'Re: Seiko 5', 'body' => '@timekeeper I know where you live. Keep posting and you will regret it.']],
    ['id' => 'harassment-mocking', 'label' => 'harassment', 'expected' => 'flagged', 'acceptable' => ['blocked'], 'context' => $thread,
        'content' => ['title' => 'Re: Wrist shot', 'body' => 'lol look at the size of that wrist, maybe stop eating for a week before posting photos @greendial']],
    ['id' => 'harassment-pile-on', 'label' => 'harassment', 'expected' => 'flagged', 'acceptable' => ['blocked'], 'context' => $thread,
        'content' => ['title' => 'Re: Re: Seiko 5', 'body' => 'Everyone go and report @timekeeper on every thread they post in until they leave. Nobody wants them here.']],

    ['id' => 'hate-dehumanising', 'label' => 'hate', 'expected' => 'blocked', 'acceptable' => ['flagged'], 'context' => $thread,
        'content' => ['title' => 'Off topic but', 'body' => 'Immigrants are parasites and this country would be better off if every last one of them was thrown out.']],
    ['id' => 'hate-exclusion', 'label' => 'hate', 'expected' => 'flagged', 'acceptable' => ['blocked'], 'context' => $thread,
        'content' => ['title' => 'Re: watch shops', 'body' => 'I never buy from shops run by Muslims, they should not be allowed to own businesses here.']],
    ['id' => 'hate-disability', 'label' => 'hate', 'expected' => 'flagged', 'acceptable' => ['blocked'], 'context' => $thread,
        'content' => ['title' => 'Re: accessibility', 'body' => 'Disabled people are a drain on everyone, why should watch brands waste money making things for them.']],

    ['id' => 'personal-doxxing', 'label' => 'personal_data', 'expected' => 'flagged', 'acceptable' => ['blocked'], 'context' => $thread,
        'content' => ['title' => 'Found the scammer', 'body' => 'The seller who ripped me off is Jamie Doe, 14 Example Road, Testville, phone 01632 960123. Go and tell him what you think.']],
    ['id' => 'personal-third-party-email', 'label' => 'personal_data', 'expected' => 'flagged', 'acceptable' => ['blocked'], 'context' => $thread,
        'content' => ['title' => 'Re: Found the scammer', 'body' => 'His personal email is jamie.doe@example.test and his bank sort code is 01-02-03 from the transfer.']],
    ['id' => 'personal-own-contact', 'label' => 'fine', 'expected' => 'passed', 'acceptable' => ['flagged'], 'context' => 'Thread: Sales corner',
        'content' => ['title' => 'FS: Orient Bambino', 'body' => 'Selling my Bambino, 120 posted. Email me at seller42@example.test if interested.']],

    ['id' => 'off-topic-politics', 'label' => 'off_topic', 'expected' => 'flagged', 'acceptable' => ['passed'], 'context' => $thread,
        'content' => ['title' => 'Election', 'body' => 'Forget watches, the real issue is the general election next month and why everyone should vote against the government.']],
    ['id' => 'off-topic-recipe', 'label' => 'off_topic', 'expected' => 'flagged', 'acceptable' => ['passed'], 'context' => $thread,
        'content' => ['title' => 'Banana bread', 'body' => 'Here is my banana bread recipe: three ripe bananas, 200g flour, 100g sugar, one egg, bake at 180 for an hour.']],
];
