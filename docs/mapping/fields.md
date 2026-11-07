# Declared Fields

Part of the [atp-parity docs](../introduction.md#documentation). Declaring a mapper's fields once for both directions, and overflowing a large field to a blob.

## Declaring fields

A mapper's `fields()` maps record paths to model columns. One declaration gives both directions. It also lets the package answer which columns end up in the record, so a save touching none of them does not write to the author's repo.

```php
use SocialDept\AtpParity\Fields\Field;

public function fields(): array
{
    return [
        'text' => 'content',
        'preferences.timezone' => Field::for('timezone')->default('UTC'),
        'theme' => Field::for('palette')->codec(ThemeCodec::class)->lossy(),
        'url' => Field::derived(fn ($model) => $model->url()),
        'avatar' => Field::for('avatar')->blob(),
    ];
}
```

A plain string names a column directly. A field is built with `Field::for($column)`, or `Field::derived($closure)` when it has no column and is only written. Everything else chains:

| Method | What it does |
|--------|--------------|
| `get(callable $get)` | Turns the record value into the model attribute. |
| `set(callable $set)` | Turns the model into the record value. |
| `default(mixed $default)` | Used when the value is null, in either direction. |
| `codec(string $codec)` | Delegates both directions to a class implementing `SocialDept\AtpParity\Contracts\RecordCodec`, which has `get()` and `set()`. |
| `enum(string $enum)` | Casts the record value to a backed enum on import, and the enum back to its value on write. |
| `lossy(bool $lossy = true)` | Marks a field whose round trip is not exact, so the parity assertions skip it. |
| `blob(bool $blob = true)` | Marks a blob field. Its reference comes from the resolver set in `blobs.resolver`, and without one the field is left out of the record. |
| `importOnly(bool $importOnly = true)` | Read from the record but never written to it. |
| `reads(string ...$columns)` | Names the other columns a `set` closure reads, so a change to them counts as a change to the record. |
| `overflowsToBlob(...)` | Moves the value to a blob once the record grows too large. See below. |

A null value is left out of the record rather than written as null, since an explicit null fails validation for a field the lexicon declares optional but typed.

Where a translation does not fit a declaration, override `recordToAttributes()` or `modelToRecordData()` instead. That is still supported and is the right choice for something like rich text. A mapper that overrides its own directions cannot report which columns its record contains, so every update syncs.

## Overflowing a large field to a blob

A record has a hard ceiling of 1 MiB, and the guidance is to keep records within a few dozen KBytes and use a blob beyond that. A field holding something open ended, such as a document body, can declare where to put it once the record no longer fits:

```php
'content.items' => Field::derived(fn ($model) => $model->blocks())
    ->overflowsToBlob(
        blob: 'content.blob',
        references: 'content.references',
    ),
```

The full signature is `overflowsToBlob(string $blob, ?string $references = null, ?int $threshold = null, string $mimeType = 'text/plain')`.

Under the threshold the field is written inline as usual. Over it, the value is uploaded as a blob, the inline path is dropped, and the record is read back the same way on import. This is opt in: a field that does not declare it is never touched.

Pass `references` wherever the value can contain blobs. A PDS collects a blob that no record references, and a blob nested inside overflowed content is invisible to it, so the images in a long record are swept unless the record keeps naming them.

`PARITY_RECORD_OVERFLOW_BYTES` (config `records.overflow_bytes`, default 20480) sets the default threshold, and a field may override it with `threshold`.

The lexicon has to accept both shapes. Adding the two properties to an existing content object is backwards compatible, since a reader that knows only `items` keeps working on every record written so far:

```json
{
  "lexicon": 1,
  "id": "com.example.content",
  "defs": {
    "main": {
      "type": "object",
      "properties": {
        "items": {
          "type": "array",
          "items": { "type": "union", "refs": [], "closed": false },
          "description": "Content blocks, written inline while the record fits"
        },
        "blob": {
          "type": "blob",
          "accept": ["application/json"],
          "maxSize": 5242880,
          "description": "The same content as JSON, written here instead of inline once the record is too large"
        },
        "references": {
          "type": "array",
          "items": { "type": "blob" },
          "description": "Every blob the content refers to, re-listed so a PDS does not collect them while the content sits in a blob"
        }
      }
    }
  }
}
```

Three things to get right:

- **The inline property cannot stay required.** An overflowed record does not carry it, so a lexicon demanding `items` rejects exactly the records this feature produces.
- **`accept` must match the field's mime type.** The default is `text/plain`, so a lexicon accepting only `application/json` needs `overflowsToBlob(..., mimeType: 'application/json')` to agree with it.
- **`maxSize` bounds the overflow, not the record.** It is the ceiling on how much content a blob may hold, well above the record threshold rather than near it.
