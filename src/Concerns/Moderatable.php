<?php

declare(strict_types=1);

namespace Phoenix1331\JevModeration\Concerns;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use LogicException;
use Phoenix1331\JevModeration\Attributes\Moderate;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use Phoenix1331\JevModeration\Moderation\ModerationSubject;
use Phoenix1331\JevModeration\Moderation\Moderator;
use Phoenix1331\JevModeration\Moderation\Reviewer;
use Phoenix1331\JevModeration\Moderation\Visibility;
use Phoenix1331\JevModeration\Policies\PolicyRegistry;
use Phoenix1331\JevModeration\Scopes\ModeratedScope;
use Phoenix1331\JevModeration\Support\StatusColumn;
use ReflectionClass;
use ReflectionMethod;
use Stringable;

/**
 * Moderates a model whenever it is created or its moderated content changes,
 * whether saved from a request, a job, Livewire or a seeder.
 *
 *     #[Moderate(fields: ['title', 'body'], policy: 'community')]
 *     class ForumPost extends Model
 *     {
 *         use Moderatable;
 *     }
 *
 * @phpstan-require-extends Model
 */
trait Moderatable
{
    /** @var array<class-string, Moderate|null> */
    private static array $moderateAttributes = [];

    /**
     * Model events, not middleware: content saved from jobs, APIs, Livewire
     * or seeders is moderated too. saveQuietly() and withoutEvents() skip it.
     */
    public static function bootModeratable(): void
    {
        static::addGlobalScope(new ModeratedScope);
        static::created(static fn (self $model) => app(Moderator::class)->saved($model->toModerationSubject(), created: true));
        static::updated(static fn (self $model) => app(Moderator::class)->saved($model->toModerationSubject(), created: false));
    }

    /**
     * Every decision, oldest first: checks, reports and human reviews.
     *
     * @return MorphMany<ModerationDecision, $this>
     */
    public function moderationDecisions(): MorphMany
    {
        return $this->morphMany(ModerationDecision::class, 'moderatable')->orderBy('id');
    }

    /**
     * The decision that sets the current status.
     *
     * @return MorphOne<ModerationDecision, $this>
     */
    public function latestModeration(): MorphOne
    {
        return $this->morphOne(ModerationDecision::class, 'moderatable')->latestOfMany();
    }

    /**
     * The latest decision's status; null when the model was never checked.
     */
    public function moderationStatus(): ?ModerationStatus
    {
        return $this->latestModeration?->status;
    }

    /**
     * Waiting for a human.
     */
    public function isFlagged(): bool
    {
        return $this->moderationStatus() === ModerationStatus::Flagged;
    }

    /**
     * Removed, automatically or by a moderator confirming it.
     */
    public function isBlocked(): bool
    {
        return in_array($this->moderationStatus(), [ModerationStatus::Blocked, ModerationStatus::Rejected], true);
    }

    /**
     * Whether the global scope would show this model under the current mode
     * and fail setting.
     */
    public function isVisible(): bool
    {
        return Visibility::fromConfig(app(Repository::class))->allows($this->moderationStatus());
    }

    /**
     * A user report: the content waits for a human. Content already blocked
     * or rejected stays removed; the report is still recorded.
     */
    public function flagForReview(string $reason, ?Model $reporter = null): ModerationDecision
    {
        return app(Reviewer::class)->report($this->toModerationSubject(), $reason, $reporter);
    }

    /**
     * A moderator overrides the flag or block: the content is shown.
     */
    public function approve(?Model $moderator = null, ?string $note = null): ModerationDecision
    {
        return app(Reviewer::class)->resolve($this->toModerationSubject(), ModerationStatus::Approved, $moderator, $note);
    }

    /**
     * A moderator confirms the flag or block: the content is removed. Pass a
     * category to reclassify, rejecting under the corrected category (one of
     * the policy's choices other than its safe choice).
     *
     * @throws InvalidArgumentException when the category is not a harmful choice of this model's policy
     */
    public function reject(?Model $moderator = null, ?string $note = null, ?string $category = null): ModerationDecision
    {
        return app(Reviewer::class)->resolve($this->toModerationSubject(), ModerationStatus::Rejected, $moderator, $note, $category);
    }

    /**
     * Check again now, even if the content has not changed (e.g. after a
     * policy change). Queued like a save, or inline with sync.
     *
     * @return ModerationDecision|null the pending decision; null when disabled or there is nothing to read
     */
    public function moderateNow(): ?ModerationDecision
    {
        return app(Moderator::class)->submit($this->toModerationSubject());
    }

    /**
     * Every model, whatever its moderation status.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithoutModeration(Builder $query): void
    {
        $query->withoutGlobalScope(ModeratedScope::class);
    }

    /**
     * Models whose latest decision has one of these statuses, visible or not.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWhereModerationStatus(Builder $query, ModerationStatus ...$statuses): void
    {
        $values = array_map(static fn (ModerationStatus $status): string => $status->value, $statuses);

        $query->withoutGlobalScope(ModeratedScope::class);

        if (StatusColumn::exists($this)) {
            $query->whereIn($this->qualifyColumn(StatusColumn::NAME), $values);

            return;
        }

        $query->whereHas('latestModeration', static fn (Builder $latest) => $latest->whereIn('status', $values));
    }

    /**
     * The review queue: models waiting for a human.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOnlyFlagged(Builder $query): void
    {
        $this->scopeWhereModerationStatus($query, ModerationStatus::Flagged);
    }

    /**
     * Models removed automatically or by a moderator.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOnlyBlocked(Builder $query): void
    {
        $this->scopeWhereModerationStatus($query, ModerationStatus::Blocked, ModerationStatus::Rejected);
    }

    /**
     * The content sent to the classifier, as field name to value. Defaults
     * to the #[Moderate] fields; empty and null fields are left out.
     *
     * @return array<string, string>
     */
    public function moderatableContent(): array
    {
        $content = [];

        foreach ((self::moderateAttribute() ?? throw self::missingAttribute())->fields as $field) {
            $value = $this->getAttribute($field);

            if ($value instanceof Stringable || is_int($value) || is_float($value)) {
                $value = (string) $value;
            }

            if ($value !== null && ! is_string($value)) {
                throw new InvalidArgumentException(sprintf(
                    'Moderated field [%s] on [%s] must be text, %s given. Override moderatableContent() to format it.',
                    $field,
                    static::class,
                    get_debug_type($value),
                ));
            }

            if ($value !== null && trim($value) !== '') {
                $content[$field] = $value;
            }
        }

        return $content;
    }

    /**
     * Material that helps the classifier understand the content, such as
     * the parent thread or previous messages. Not part of the content hash.
     */
    public function moderationContext(): ?string
    {
        return null;
    }

    /**
     * Who wrote the content: the user relation when the model has one.
     */
    public function moderationAuthor(): ?Model
    {
        if (! method_exists($this, 'user') || ! $this->user() instanceof Relation) {
            return null;
        }

        $author = $this->getRelationValue('user');

        return $author instanceof Model ? $author : null;
    }

    /**
     * The policy name: from #[Moderate], else jev-moderation.policy.
     */
    public function moderationPolicy(): string
    {
        return self::moderateAttribute()->policy ?? app(PolicyRegistry::class)->defaultName();
    }

    /**
     * @internal read by the Moderator on every save
     */
    public function toModerationSubject(): ModerationSubject
    {
        $content = $this->moderatableContent();

        return new ModerationSubject(
            model: $this,
            policy: $this->moderationPolicy(),
            content: $content,
            context: $this->moderationContext(),
            hash: ModerationSubject::hash($content),
            fields: self::overridesContent() ? null : self::moderateAttribute()?->fields,
        );
    }

    private static function moderateAttribute(): ?Moderate
    {
        if (! array_key_exists(static::class, self::$moderateAttributes)) {
            $attributes = (new ReflectionClass(static::class))->getAttributes(Moderate::class);

            self::$moderateAttributes[static::class] = $attributes === [] ? null : $attributes[0]->newInstance();
        }

        return self::$moderateAttributes[static::class];
    }

    /**
     * A method that comes from this trait reports the trait's file; an
     * override is declared anywhere else.
     */
    private static function overridesContent(): bool
    {
        return (new ReflectionMethod(static::class, 'moderatableContent'))->getFileName() !== (new ReflectionClass(Moderatable::class))->getFileName();
    }

    private static function missingAttribute(): LogicException
    {
        return new LogicException(sprintf(
            '[%s] uses Moderatable but has no #[Moderate(fields: [...])] attribute. Add one, or override moderatableContent().',
            static::class,
        ));
    }
}
