# Upgrading

## 0.6 to 1.0

Two changes need action. Everything else is additive, and an existing mapper that
overrides its own directions keeps working untouched.

### 1. A mapper now imports nothing until it says what it accepts

Every mapper is an ingest boundary: records arrive from the whole network, so a
mapper that accepts everything lets any repo write rows in your database. That was
the default. It is now the opposite.

```php
public function shouldImport(Data $record, array $meta = []): bool
{
    return $this->accepts()?->permits($record, $meta, $this) ?? false;
}
```

**You are unaffected if your mapper overrides `shouldImport()`**, because an override
replaces that body entirely. That is deliberate, and it is what let this land without
rewriting every mapper.

**You are affected if your mapper relies on the old default.** It will silently
import nothing. Declare what it accepts:

```php
use SocialDept\AtpParity\Acceptance\Acceptance;

public function accepts(): ?Acceptance
{
    return Acceptance::ownWritesOnly();
}
```

Available: `anything()`, `none()`, `when(callable)`, `ownWritesOnly()`,
`localDids()`, and `->and()` / `->or()` to compose. `anything()` is legitimate for a
genuinely public collection and is deliberately verbose so it reads as a decision.

`ownWritesOnly()` and `localDids()` ask whether a DID is one of yours, which only
your app knows, so configure the lookup:

```php
// config/atp-parity.php
'acceptance' => [
    'local_dids' => fn (string $did) => User::where('did', $did)->exists(),
],
```

Without it those policies accept nothing. A missing lookup must not read as
"everything is local".

### 2. `SchemaMapper` takes an acceptance

```php
new SchemaMapper(
    schemaClass: Post::class,
    modelClass: PostModel::class,
    toAttributes: ...,
    toRecordData: ...,
    accepts: Acceptance::ownWritesOnly(), // new, and required in practice
);
```

A mapper assembled at a call site is still an ingest boundary. Omitting it imports
nothing.

### 3. The contract gained three methods

`SocialDept\AtpParity\Contracts\RecordMapper` now declares `fields()`,
`recordColumns()` and `accepts()`. **Extending `RecordMapper` gives you all three.**
Only a class implementing the interface directly needs to add them.

### 4. The two direction methods are no longer abstract

`recordToAttributes()` and `modelToRecordData()` have defaults that read your
`fields()` declaration. A mapper that declares neither a field map nor an override
used to fail at compile time and now throws a `LogicException` when first used,
because silently mapping nothing is worse than either.

---

## What is new, and optional

### Declare fields instead of writing both directions

```php
public function fields(): array
{
    return [
        'name' => 'name',
        'preferences.timezone' => Field::for('timezone')->default('UTC'),
        'theme' => Field::for('palette')->codec(ThemeCodec::class)->lossy(),
        'url'  => Field::derived(fn ($model) => $model->url()),   // no column, write only
        'icon' => Field::for('icon')->blob(),
    ];
}
```

A field is built with `Field::for($column)`, or `Field::derived($closure)` for one
with no column that is only ever written. Everything else chains, including `get()`
and `set()` for either direction.

What the declaration buys, beyond writing each default once:

- `recordColumns()` reports which columns feed the record, so a save touching none of
  them no longer resyncs. Before this, any column on the row was a trigger.
- A one-directional field is stated rather than inferred from an absence.
- A lossy translation is declarable, so round-trip assertions tolerate it
  deliberately.

### Test your mappers

```php
use SocialDept\AtpParity\Testing\AssertsRecordParity;

$this->assertRecordParity(new MyMapper, $savedModel);
```

Checks that the two directions are inverses, and that ingesting a record you wrote
changes nothing. The second is the one that matters: your own writes come back as
firehose events, so a mapper whose ingest is not a no-op dirties the row, which
resyncs, which writes, which produces the next event.

A mapper that writes its own directions skips rather than passes, since unknowable is
not the same as correct.

### Migrate records whose shape has moved on

Lexicons are not versioned documents, so a step infers the old shape from the record
itself. That also works on records other clients wrote.

```php
#[UpcastsFrom(lexicon: 'app.example.theme')]
final class SplitPalette extends Upcaster
{
    public function applies(array $record): bool
    {
        return isset($record['colors']) && ! isset($record['light']);
    }

    public function apply(array $record): array
    {
        $record['light'] = $record['colors'];

        return $record;
    }

    public function onWrite(): ?Deprecation
    {
        return Deprecation::dualWrite('colors', from: 'light');
    }
}
```

Register the class in `atp-parity.upcasters`. It runs on the raw array before the DTO
is hydrated, because the generated DTO is the current lexicon and an older record
hydrated into it either fails or loses fields.

Two rules, both enforced by tests you should copy:

- `applies()` must be false for a record already current, or every read re-dirties the
  row and the resync writes to a PDS.
- `apply()` must be idempotent, which is the only thing that makes a step safe where a
  shape difference is ambiguous.

**A change you cannot detect is a change you cannot migrate.** When a generation adds
only an optional field, its absence is ambiguous. Prefer changes that leave a
fingerprint, and say in review how a reader tells old from new.

### Keep blob uploads out of record construction

Set `atp-parity.blobs.resolver` to a `BlobResolver`. It runs before construction and
is the only phase allowed I/O, which is what makes "what would we write" cheap enough
to ask before every write.

### Carried over from 0.6

- A resync is skipped when the repo already holds the record byte for byte, compared
  by CID. `PARITY_SYNC_SKIP_UNCHANGED=false` disables it.
- `resync(force: true)` writes regardless. Use it for repair: an equal CID proves what
  you last wrote, not what the repo still holds.
