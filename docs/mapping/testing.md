# Testing Mappers

Part of the [atp-parity docs](../introduction.md#documentation). The assertions the package ships for checking each of your mappers.

## Parity assertions

Use the `SocialDept\AtpParity\Testing\AssertsRecordParity` trait in a test case and point it at each of your mappers:

```php
use SocialDept\AtpParity\Testing\AssertsRecordParity;

class PostMapperTest extends TestCase
{
    use AssertsRecordParity;

    public function test_post_mapper_keeps_parity(): void
    {
        $this->assertRecordParity(new PostMapper, $savedPost);
    }
}
```

`assertRecordParity()` runs two assertions, which can also be called on their own:

- **`assertRecordRoundTrips(RecordMapper $mapper, Model $model)`**: the model survives being turned into a record and read back, apart from the columns the mapper declares `lossy()`. A mapper that overrides its own directions instead of declaring `fields()` cannot report its columns, so this assertion marks the test skipped.
- **`assertIngestIsIdempotent(RecordMapper $mapper, Model $model, ?Data $record = null)`**: ingesting a record the app wrote changes nothing. The model must be saved.

The second one is the important one. Your own writes come back as firehose events, so a mapper whose ingest is not a no-op dirties the row, which resyncs, which writes, which produces the next event.
