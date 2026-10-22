<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Data;

use InvalidArgumentException;

/**
 * One moderation policy from config: the question Jev answers, its choices,
 * the choice that means nothing is wrong, and a rule per risky category.
 * The version is a hash of all of it, stored on every decision so threshold
 * and wording changes stay traceable.
 */
final readonly class Policy
{
    public string $version;

    /**
     * @param  array<string, string>  $choices  choice name to description ('' when the name says it all)
     * @param  array<string, CategoryRule>  $rules  category to rule; categories without one never flag
     * @param  float|null  $uncertainBelow  flag any answer whose confidence is below this
     */
    public function __construct(
        public string $name,
        public string $question,
        public array $choices,
        public string $safeChoice,
        public array $rules,
        public ?float $uncertainBelow = null,
    ) {
        if (trim($question) === '') {
            throw self::invalid($name, 'needs a question');
        }

        if (count($choices) < 2 || count($choices) > ModerationRequest::MAX_CHOICES) {
            throw self::invalid($name, sprintf('needs between 2 and %d choices, %d given', ModerationRequest::MAX_CHOICES, count($choices)));
        }

        if (! array_key_exists($safeChoice, $choices)) {
            throw self::invalid($name, "safe choice [{$safeChoice}] is not one of its choices");
        }

        foreach ($rules as $category => $rule) {
            if ($category !== $rule->category) {
                throw self::invalid($name, "rule under [{$category}] is for [{$rule->category}]");
            }

            if (! array_key_exists($category, $choices)) {
                throw self::invalid($name, "has a rule for [{$category}], which is not one of its choices");
            }

            if ($category === $safeChoice) {
                throw self::invalid($name, "has a rule for its safe choice [{$category}]");
            }
        }

        if ($uncertainBelow !== null && ($uncertainBelow < 0.0 || $uncertainBelow > 1.0)) {
            throw self::invalid($name, sprintf('uncertain_below must be between 0 and 1, %s given', $uncertainBelow));
        }

        $this->version = hash('sha256', json_encode([
            'question' => $question,
            'choices' => $choices,
            'safe_choice' => $safeChoice,
            'rules' => array_map(static fn (CategoryRule $rule): array => [$rule->reviewAbove, $rule->blockAbove], $rules),
            'uncertain_below' => $uncertainBelow,
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * Build a policy from its config array, guarding every value.
     */
    public static function fromConfig(string $name, mixed $config): self
    {
        if (! is_array($config)) {
            throw self::invalid($name, sprintf('must be an array, %s given', get_debug_type($config)));
        }

        $question = $config['question'] ?? null;
        $safeChoice = $config['safe_choice'] ?? null;
        $actions = $config['actions'] ?? [];
        $uncertainBelow = $config['uncertain_below'] ?? null;

        if (! is_string($question)) {
            throw self::invalid($name, 'needs a question');
        }

        if (! is_string($safeChoice)) {
            throw self::invalid($name, 'needs a safe_choice');
        }

        if (! is_array($actions)) {
            throw self::invalid($name, 'actions must be an array');
        }

        if ($uncertainBelow !== null && ! is_int($uncertainBelow) && ! is_float($uncertainBelow)) {
            throw self::invalid($name, 'uncertain_below must be a number or null');
        }

        $rules = [];

        foreach ($actions as $category => $action) {
            if (! is_string($category)) {
                throw self::invalid($name, 'actions must be keyed by category name');
            }

            $rules[$category] = self::rule($name, $category, $action);
        }

        return new self(
            name: $name,
            question: $question,
            choices: self::choices($name, $config['choices'] ?? null),
            safeChoice: $safeChoice,
            rules: $rules,
            uncertainBelow: $uncertainBelow === null ? null : (float) $uncertainBelow,
        );
    }

    /**
     * The request to classify some content under this policy.
     *
     * @param  array<string, string>  $content  field name to value
     */
    public function request(array $content, ?string $context = null): ModerationRequest
    {
        return new ModerationRequest($this->name, $this->question, $this->choices, $content, $context, $this->safeChoice);
    }

    /**
     * A map of name to description, or a list of names.
     *
     * @return array<string, string>
     */
    private static function choices(string $policy, mixed $raw): array
    {
        if (! is_array($raw)) {
            throw self::invalid($policy, 'needs choices');
        }

        $choices = [];

        foreach ($raw as $key => $value) {
            [$name, $description] = is_int($key) ? [$value, ''] : [$key, $value];

            if (! is_string($name) || $name === '' || ! is_string($description)) {
                throw self::invalid($policy, 'choices must be a list of names or a map of name to description');
            }

            $choices[$name] = $description;
        }

        return $choices;
    }

    private static function rule(string $policy, string $category, mixed $action): CategoryRule
    {
        $review = is_array($action) ? ($action['review_above'] ?? null) : null;
        $block = is_array($action) ? ($action['block_above'] ?? null) : null;

        if (! is_int($review) && ! is_float($review)) {
            throw self::invalid($policy, "action for [{$category}] needs a numeric review_above");
        }

        if ($block !== null && ! is_int($block) && ! is_float($block)) {
            throw self::invalid($policy, "action for [{$category}] has a block_above that is not a number");
        }

        return new CategoryRule($category, (float) $review, $block === null ? null : (float) $block);
    }

    private static function invalid(string $policy, string $problem): InvalidArgumentException
    {
        return new InvalidArgumentException("Moderation policy [{$policy}] {$problem}.");
    }
}
