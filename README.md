# WordPress Venue Management

A WordPress plugin for venues that run a public programme: a weekly trivia night, a monthly reading, a seasonal popup. Each date is its own event. Dates that belong together form a series. A saved rule copies a template event forward — weekly, every N weeks, monthly on a chosen day, or yearly — and a cron job keeps the future filled.

Visitors get an archive split into upcoming and past, a single event page, an iCalendar download, an “Add to Google Calendar” link, and Event structured data. Organizers get venues they can add from wp-admin, CSV and JSON import, bulk edits across a series, and a default image per series.

The programme is a list of occurrences. Ticketing stays an external URL on the event. Themes supply their own templates when they want a different layout.

Stored datetimes are naive site-local strings (`Y-m-d H:i:s`). They are parsed with `wp_timezone()`, because `strtotime()` on a string with no zone follows the server clock and will show a 7pm event as 3pm on a UTC host.

## Status

| | |
| --- | --- |
| Version | [0.2.0](https://github.com/amandadr/wordpress-venue-management/releases/tag/v0.2.0) |
| License | [GPL-2.0-or-later](LICENSE) |
| Requires | WordPress 6.2+, PHP 8.0+ |
| Optional | Advanced Custom Fields, for the venue and ticket fields |

`1.0.0` follows after the weekly populate cron has run for a full cycle on a live site. Uninstall clears that cron hook and a rewrite flag. Events, series, and venues stay in the database until someone deletes them in wp-admin.

## Install

1. Copy this directory to `wp-content/plugins/wordpress-venue-management`, or install the zip from the Plugins screen.
2. Activate **WordPress Venue Management**.
3. Add rooms under **Events → Venues**, and a series under **Events → Series**.
4. Create one event with a start time and assign the series.
5. Open **Events → Series tools**, save a rule, and generate dates.
6. Visit `/events/`.

If another plugin or theme already registers an archive at `/events/`, wp-admin warns you. Public URLs belong to whichever post type WordPress registered first. Filter `wvm_archive_slug` before `init` priority 5 to pick a different slug.

## Venues and series

Venues are terms on `venue_place`: name, slug, public label, and address. The archive URL `/{archive}/{venue-slug}/` is built from those terms. An event stores the venue term id. Choose **Other address** when the address belongs only to that one date.

Series are terms on `venue_series`. The term can hold a default image and title patterns, one phrase per line. **Series tools → Assign empty events from title patterns** fills a series only on events that have none. A rename keeps the image, because it is stored by term id.

Advanced Custom Fields is optional. When it is active, venue and ticket URL also appear in an ACF group and write the same meta the event box writes.

## Recurrence

A rule lives on the series term (`_venue_series_recurrence`). **Series tools** saves it and can generate immediately. The weekly cron hook `wvm_populate_series` is scheduled on activation and cleared on deactivation. It finds the latest start in each series and extends the rule. Opening wp-admin is not required.

| Rule | What it does |
| --- | --- |
| Weekly | Same weekday and clock time |
| Every N weeks | Interval from 1 to 12 |
| Monthly | Day 1–31, clamped to the length of the month |
| Yearly | Same month and day, clamped in short months |

The template’s start and end set the clock time and the duration. A missing end becomes one hour. A date-only “generate from” value keeps that clock time. Steps use `DateTimeImmutable` in the site timezone, so a weekly 7pm rule stays at 7pm across a daylight-saving change.

Generation skips a start that already exists in the series (the first 19 characters of `Y-m-d H:i:s`). A manual run is capped at 26 weeks or 6 months, and at the horizon (`wvm_recurrence_horizon_months`, default 6). An interval of six months or more creates one occurrence per run. New events copy the title, content, excerpt, cost, venue, ticket URL, and featured image. The status defaults to `publish` and is filterable, so a site can generate drafts. Slugs look like `{series}-{Y-m-d}-{timestamp}` and can be changed with `wvm_generated_event_postarr`.

## Import and export

**Events → Import & export** reads CSV or JSON with the same columns, and writes that CSV back out:

`title`, `slug`, `start`, `end`, `cost`, `venue_slug`, `address`, `ticket_url`, `series`, `excerpt`, `content`, `image_url`

`start` and `end` are site-local `Y-m-d H:i:s`. Rows match an existing event by slug, then by title plus start date. Updating matches is the default. Sideloading `image_url` is optional. Trashing published events that are missing from the file stays off unless an administrator checks both confirm boxes. `samples/events.csv` is fictional.

`wvm_import_row` receives each row before it is written. `wvm_events_imported` fires when the file finishes, with `created`, `updated`, `skipped`, `trashed`, and `errors`.

## Templates

The plugin ships plain templates that call the theme’s normal header and footer. A theme file wins when it exists:

| View | Theme file | Plugin fallback |
| --- | --- | --- |
| Archive | `venue-event/archive.php` | `templates/archive-venue-event.php` |
| Single | `venue-event/single.php` | `templates/single-venue-event.php` |
| Card | `venue-event/event-card.php` | `templates/event-card.php` |

`wvm_get_upcoming_events()` and `wvm_get_past_events()` run the same queries the archive uses. Pass a venue slug to limit either list. `wvm_render_event_card()` prints one card. Upcoming events start today or later, soonest first. Past events are newest first. Both lists stop at 50 posts.

`assets/venue-events.css` loads on the archive, the single event, and a search under the archive path. Return false from `wvm_enqueue_styles` to leave styling to the theme. A search under `/events/` uses the events template, the document title “Events” or “Events: {query}”, and the archive body classes.

An event with no featured image and no series image renders no image.

## Hooks

Register `wvm_post_type` and `wvm_archive_slug` before `init` priority 5. The other filters can be added at any time.

| Filter | Arguments | Default |
| --- | --- | --- |
| `wvm_post_type` | `string $slug` | `venue_event` |
| `wvm_archive_slug` | `string $slug` | `events` |
| `wvm_event_meta_keys` | `array $keys` purpose => meta key | `_venue_event_*` |
| `wvm_venues` | `array $venues` each `term_id`, `slug`, `name`, `label`, `address` | stored venue terms |
| `wvm_recurrence_horizon_months` | `int $months` | `6` |
| `wvm_generated_event_postarr` | `array $postarr`, `WP_Post $template`, `int $series_term_id` | copied title, content, excerpt, and a unique slug |
| `wvm_generated_event_status` | `string $status`, `WP_Post $template`, `int $series_term_id` | `publish` |
| `wvm_import_row` | `array $row`, `int $index` | the CSV or JSON row |
| `wvm_schema` | `array $schema`, `int $post_id` | Schema.org `Event` |
| `wvm_ics` | `string $ics`, `int $post_id` | one `VEVENT` |
| `wvm_enqueue_styles` | `bool $enqueue` | `true` |

| Action | Arguments |
| --- | --- |
| `wvm_event_generated` | `int $post_id`, `int $series_term_id`, `int $template_id` |
| `wvm_series_populated` | `int $created`, `int $series_processed` |
| `wvm_events_imported` | `array $result` with `created`, `updated`, `skipped`, `trashed`, `errors` |

```php
add_filter( 'wvm_recurrence_horizon_months', function () {
	return 3;
} );

add_filter( 'wvm_generated_event_status', function () {
	return 'draft';
} );
```

Reads of event meta fall back to the older `_grb_event_*` keys when the new key is empty. `grb_event_venues` and `grb_event_series_match_patterns` still run, after the stored venues and series patterns. Those two filters are deprecated.

## A site that already has `grb_event`

**Events → Migrate legacy programme** copies `grb_event` posts and `grb_series` terms onto `venue_event` and `venue_series`. Events that already share a slug are skipped. Recurrence rules are pointed at the copied template. The original posts and terms are left in place. Remove the old registration after you have compared counts, the next month of start times, and one `.ics` file.

## Coming next

The programme above is the tool. These are the additions planned after 0.2.0:

- An iCalendar feed for a whole venue or series, beside the per-event download.
- A way to skip or cancel one date without changing the series rule.
- Monthly rules by weekday, such as the first Thursday, beside the day-of-month rule.
- WP-CLI commands for import, export, and extending saved rules.
- Block theme templates alongside the PHP templates.

## Layout

```
wordpress-venue-management.php
uninstall.php
readme.txt
includes/
  class-plugin.php
  class-post-type.php
  class-meta.php
  class-venues.php
  class-series.php
  class-recurrence.php
  class-importer.php
  class-migrator.php
  class-feeds.php
  class-templates.php
  class-acf.php
  class-admin.php
  template-tags.php
templates/
  archive-venue-event.php
  single-venue-event.php
  event-card.php
assets/
  venue-events.css
  series-image.js
samples/
  events.csv
```

## License

[GPL-2.0-or-later](LICENSE). WordPress plugins that run inside WordPress are distributed under the GPL.
