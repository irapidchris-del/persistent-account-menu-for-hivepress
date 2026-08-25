# HivePress Listing Expiration Fix

A drop-in WordPress plugin that stops HivePress's hourly job from expiring, drafting and
trashing listings that are not due, and puts back the listings it already took down.

Written for the incident reported in
[After v. 1.7.29 Listings expired and disappear](https://community.hivepress.io/t/after-v-1-7-29-listings-expired-and-disappear/17932).

It is a stopgap. Once HivePress ships the fix described at the end of this file, deactivate
and delete this plugin.

---

## What happened

`HivePress\Components\Listing::expire_listings()` runs on `hivepress/v1/events/hourly` and
does three things, ten listings at a time:

1. moves published and pending listings whose expiration date has passed to **draft**,
2. moves drafts that expired longer ago than the storage period to the **trash**,
3. clears the featured flag on listings whose featuring period has passed.

Each pass asks HivePress for the listings to act on and then acts on all of them without
checking anything. So the whole job is only ever as safe as the query behind it, and in
1.7.29 that query stopped filtering.

### The regression

HivePress builds meta comparisons from the model's field definitions. `Queries\Query::filter()`
reads the comparison type straight off the field class:

```php
$clause = array_merge( $clause, [
    'type'  => $field::get_meta( 'type' ),
    'value' => $value,
] );
```

1.7.29 retyped the two timestamp fields in `Models\Listing`:

```diff
 'expired_time'  => [
-    'type'      => 'number',
-    'min_value' => 0,
+    'type'      => 'date',
+    'format'    => 'U',
     '_external' => true,
 ],
```

`Fields\Number` declares `'type' => 'DECIMAL'`; `Fields\Date` declares `'type' => 'DATE'`.
So the clause `expired_time__lte => time()` went from

```sql
CAST(meta_value AS DECIMAL) <= '1787918400'
```

to

```sql
CAST(meta_value AS DATE) <= '1787918400'
```

`hp_expired_time` holds a Unix timestamp, and MySQL cannot read a Unix timestamp as a
calendar date. Both sides fail to convert, and the comparison stops meaning anything.
On MariaDB 10.11 it does not fail closed, it comes out **true**:

```
MariaDB> SELECT CAST('1819192944' AS DATE) <= '1787918400';   -- expires in 2027
1
```

Run against a listing table, the "expired listings" clause matches every row:

| | rows matched by `CAST(... AS DATE) <= now` | rows matched by `CAST(... AS DECIMAL) <= now` |
| --- | --- | --- |
| expires in 1 day | ✅ matched | — |
| expires in 30 days | ✅ matched | — |
| expires in 1 year | ✅ matched | — |
| expires in 2031 | ✅ matched | — |
| **already expired** | ✅ matched | ✅ matched |

That is the whole bug. The hourly job was handed every live listing and dutifully drafted
ten of them, then trashed ten of the drafts, every hour.

### Verified end to end

On a clean WordPress 7.1 install with HivePress 1.7.29 and Paid Listings 1.1.9, 21 listings
seeded across a spread of expiration dates, three runs of `hivepress/v1/events/hourly`:

```
EXPIRES          BEFORE        AFTER
future +365d     publish=3     trash=3
future +30d      publish=3     trash=3
future +1d       publish=3     trash=3
PAST -1d         publish=3     trash=3
PAST -40d        publish=3     trash=3
no expiry        publish=3     publish=3     <- only survivors
future +2000d    publish=3     trash=3
```

Only listings with **no** expiration date survived, which matches the reports in the thread
("All listings had disappeared, except for my own"). The stored `hp_expired_time` values were
**not** altered by the fault — the data is intact, only the statuses were changed.

---

## Why 1.7.30 has not ended it

1.7.30 introduces `Fields\Timestamp` (`'type' => 'DECIMAL'`) and retypes both fields to it.
That is the right fix for the cast, and on a clean install it works: the same test above
leaves every future-dated listing published.

But the cast was only one way into the trap. The real fault is that
`Queries\Query::filter()` **silently discards a criterion it cannot resolve**:

```php
$field = hp\get_array_value( $this->model->_get_fields(), $name );

if ( $field ) {
    // ...build the meta clause...
}
// no else: an unknown field means the criterion just disappears
```

and `Models\Model::_set_fields()` drops any field whose class cannot be instantiated:

```php
$field = hp\create_class_instance( '\HivePress\Fields\\' . $args['type'], [ ... ] );

if ( $field ) {
    $this->fields[ $name ] = $field;
}
```

So on 1.7.30, if `HivePress\Fields\Timestamp` cannot be resolved for any reason, the
`expired_time` field vanishes from the model, `expired_time__lte` vanishes from the query,
and `expire_listings()` is left running

```php
Models\Listing::query()->filter( [ 'status__in' => [ 'pending', 'publish' ] ] )
    ->order( 'random' )->limit( 10 )->get();
```

which is "ten random live listings". Reproduced by deleting `includes/fields/class-timestamp.php`
from an otherwise stock 1.7.30 and running the job eight times:

```
EXPIRES          AFTER
future +365d     publish=3
future +30d      publish=2 trash=1
future +1d       trash=2 publish=1
PAST -1d         trash=3
PAST -40d        publish=2 trash=1
no expiry        trash=1 publish=2      <- listings with no expiry date, trashed
future +2000d    trash=2 publish=1
```

Identical symptom, on a plugin that reports itself as 1.7.30. `class-timestamp.php` is a
brand-new file introduced by the emergency release, which is exactly the kind of file an
install can end up without: an opcode cache pinned with `opcache.validate_timestamps=0`
still serving the old `class-listing.php`, an update that unpacked partially, a staging
sync or CDN that copied changed files but not new ones, a security layer that blocked the
write. In every one of those cases the version header reads 1.7.30 and the site behaves
like 1.7.29.

The wp.org 1.7.30 package and the GitHub 1.7.30 package are byte-identical outside
`vendor/`, so this is not a bad build — it is a site-state problem that the code has no
defence against.

**The Tools screen this plugin adds tells you which state a site is in**: whether HivePress
would compare expiration dates as numbers (correct), as calendar dates (the 1.7.29 fault),
or not at all (the field is missing and the filter would be dropped).

---

## What this plugin does

**Takes over the hourly job.** On `hivepress/v1/setup` it removes HivePress's
`expire_listings` from `hivepress/v1/events/hourly` and runs its own copy. If it cannot
remove the core handler it does not add its own — running both would leave the unsafe one
in place — and it says so with an admin notice instead.

**Never relies on a query alone.** Its version of the job

- builds the meta comparison itself, pinned to `'type' => 'NUMERIC'`, so no field
  definition can change what the SQL means, and
- reads `hp_expired_time` back with `get_post_meta()` and compares it in PHP before
  touching a listing. A value that is not a positive integer is treated as "no expiration
  date", so a corrupt value leaves the listing alone rather than reading as long expired.

Everything else is kept as core has it: the same batch size of ten, the same random order,
the same `Emails\Listing_Expire` notification, the same storage-period and featured passes.
On a healthy 1.7.30 install the outcome is identical to core's.

**Puts back what was taken down.** Tools → Listing Expiration Fix lists every listing that
is in draft or in the trash while its expiration date is still ahead of it, and republishes
them 200 at a time. HivePress's `hivepress/v1/models/listing/update` and
`.../update_status` hooks are suspended while it does, so republishing does not re-send
approval emails and, on sites running Paid Listings, does not spend another listing from
the owner's package. (The snippet posted in the forum thread runs on `template_redirect`,
which means on every front-end request until it is removed, and does fire those hooks.)

One caveat, the same one the forum snippet carries: a listing you hid yourself will be
republished too if its expiration date is still ahead. In practice HivePress only sets
`hp_expired_time` when a listing moves to published or pending, so a listing that was never
live is unlikely to appear — but the screen lists every candidate with its status and last
modified date so you can check before clicking.

## Installing

1. Zip the `hivepress-listing-expiration-fix` directory.
2. Plugins → Add New Plugin → Upload Plugin.
3. Activate. It starts protecting the site immediately; there is nothing to configure.
4. Go to **Tools → Listing Expiration Fix** to see the site's state and republish anything
   that was taken down early.

Requires WordPress 5.8+, PHP 7.4+ and HivePress. It works on 1.7.28, 1.7.29 and 1.7.30 —
including on 1.7.29, if updating is not an option right now.

## Removing it

Deactivate and delete. HivePress's own hourly job takes over again on the next page load.
Deleting also removes the two options it stores (`hplef_last_report`, `hplef_restored`).

---

## The fix HivePress should ship

Retyping the fields fixed one route in. The job is still one bad query away from taking a
site down, so it should stop trusting the query. In
`includes/components/class-listing.php`:

```diff
 	public function expire_listings() {
+		$time = time();
+
 		// Get expired listings.
 		$expired_listings = Models\Listing::query()->filter(
 			[
 				'status__in'        => [ 'pending', 'publish' ],
-				'expired_time__lte' => time(),
+				'expired_time__lte' => $time,
 			]
 		)->order( 'random' )
 		->limit( 10 )
 		->get();
 
 		// Update expired listings.
 		foreach ( $expired_listings as $listing ) {
+
+			// Skip listings that aren't expired.
+			if ( ! $listing->get_expired_time() || $listing->get_expired_time() > $time ) {
+				continue;
+			}
+
 			// Update status.
 			$listing->set_status( 'draft' )->save_status();
```

The same guard belongs on the other two passes. The storage pass needs restructuring
slightly, because `->trash()` on the query object gives it no chance to check:

```diff
 		if ( $storage_period ) {
+			$storage_time = $time - DAY_IN_SECONDS * $storage_period;
+
 			// Delete stored listings.
-			Models\Listing::query()->filter(
+			$stored_listings = Models\Listing::query()->filter(
 				[
 					'status'            => 'draft',
-					'expired_time__lte' => time() - DAY_IN_SECONDS * $storage_period,
+					'expired_time__lte' => $storage_time,
 				]
 			)->order( 'random' )
 			->limit( 10 )
-			->trash();
+			->get();
+
+			foreach ( $stored_listings as $listing ) {
+				if ( ! $listing->get_expired_time() || $listing->get_expired_time() > $storage_time ) {
+					continue;
+				}
+
+				$listing->trash();
+			}
 		}
```

and the featured pass:

```diff
 		foreach ( $featured_listings as $listing ) {
+			if ( ! $listing->get_featured_time() || $listing->get_featured_time() > $time ) {
+				continue;
+			}
+
 			$listing->fill(
```

Three guards, about a dozen lines. Any one of them would have contained this incident to
"listings stopped expiring" instead of "the site emptied itself", and they hold whichever
way the query breaks next.

Worth considering alongside it: `Queries\Query::filter()` dropping an unresolvable
criterion without a word is what turns a missing field class into a destructive query.
Raising `_doing_it_wrong()` there when `WP_DEBUG` is on would have made both faults visible
in a log instead of in the trash.
