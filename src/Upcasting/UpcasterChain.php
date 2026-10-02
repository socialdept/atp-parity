<?php

namespace SocialDept\AtpParity\Upcasting;

use LogicException;
use ReflectionClass;
use SocialDept\AtpParity\Attributes\UpcastsFrom;

/**
 * Brings a record of any past shape up to the current one, so mappers only ever see
 * the current shape.
 *
 * INFO: must run on the raw array before the DTO is hydrated. The generated DTO is
 * the current lexicon, so an older record hydrated into it fails validation or
 * loses whatever the new shape does not declare.
 */
class UpcasterChain
{
    /**
     * @var array<string, array<int, Upcaster>>
     */
    protected array $chains = [];

    /**
     * @param  array<int, class-string<Upcaster>|Upcaster>  $upcasters
     */
    public function __construct(array $upcasters = [])
    {
        $this->chains = $this->build($upcasters);
    }

    /**
     * Each step runs at most once per pass, so a step that mis-declares `applies()`
     * cannot loop.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function upcast(string $lexicon, array $record): array
    {
        foreach ($this->chains[$lexicon] ?? [] as $upcaster) {
            if ($upcaster->applies($record)) {
                $record = $upcaster->apply($record);
            }
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public function applyDeprecations(string $lexicon, array $record): array
    {
        foreach ($this->chains[$lexicon] ?? [] as $upcaster) {
            $record = $upcaster->onWrite()?->applyTo($record) ?? $record;
        }

        return $record;
    }

    /**
     * Which steps a record is behind, without writing anything.
     *
     * @param  array<string, mixed>  $record
     * @return array<int, class-string<Upcaster>>
     */
    public function pending(string $lexicon, array $record): array
    {
        $pending = [];

        foreach ($this->chains[$lexicon] ?? [] as $upcaster) {
            if ($upcaster->applies($record)) {
                $pending[] = $upcaster::class;
                $record = $upcaster->apply($record);
            }
        }

        return $pending;
    }

    public function isCurrent(string $lexicon, array $record): bool
    {
        return $this->pending($lexicon, $record) === [];
    }

    /**
     * @return array<string, array<int, Upcaster>>
     */
    public function lexicons(): array
    {
        return $this->chains;
    }

    /**
     * @param  array<int, class-string<Upcaster>|Upcaster>  $upcasters
     * @return array<string, array<int, Upcaster>>
     */
    protected function build(array $upcasters): array
    {
        $byLexicon = [];

        foreach ($upcasters as $upcaster) {
            $instance = is_string($upcaster) ? app($upcaster) : $upcaster;
            $byLexicon[$this->lexiconOf($instance)][] = $instance;
        }

        foreach ($byLexicon as $lexicon => $instances) {
            $byLexicon[$lexicon] = $this->order($lexicon, $instances);
        }

        return $byLexicon;
    }

    protected function lexiconOf(Upcaster $upcaster): string
    {
        return $this->attributeOf($upcaster)->lexicon;
    }

    /**
     * Fails rather than registering under no lexicon and never running.
     */
    protected function attributeOf(Upcaster $upcaster): UpcastsFrom
    {
        $attributes = (new ReflectionClass($upcaster))->getAttributes(UpcastsFrom::class);

        if ($attributes === []) {
            throw new LogicException($upcaster::class.' is an Upcaster with no #[UpcastsFrom] attribute, so it declares no lexicon.');
        }

        return $attributes[0]->newInstance();
    }

    /**
     * @param  array<int, Upcaster>  $instances
     * @return array<int, Upcaster>
     */
    protected function order(string $lexicon, array $instances): array
    {
        $byClass = [];

        foreach ($instances as $instance) {
            $byClass[$instance::class] = $instance;
        }

        $ordered = [];
        $placing = [];

        $place = function (Upcaster $instance) use (&$place, &$ordered, &$placing, $byClass, $lexicon): void {
            $class = $instance::class;

            if (array_key_exists($class, $ordered)) {
                return;
            }

            if (array_key_exists($class, $placing)) {
                throw new LogicException("Upcasters for {$lexicon} declare a cycle through {$class}.");
            }

            $placing[$class] = true;
            $after = $this->attributeOf($instance)->after;

            if ($after !== null) {
                if (! array_key_exists($after, $byClass)) {
                    throw new LogicException("{$class} declares after: {$after}, which is not a registered upcaster for {$lexicon}.");
                }

                $place($byClass[$after]);
            }

            unset($placing[$class]);
            $ordered[$class] = $instance;
        };

        foreach ($instances as $instance) {
            $place($instance);
        }

        return array_values($ordered);
    }
}
