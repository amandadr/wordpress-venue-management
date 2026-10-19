# WordPress Venue Management

A WordPress plugin for venues that run a public programme: a weekly trivia night, a monthly comedy show, a seasonal popup. Each date is its own event. Dates that belong together form a series. A saved rule copies a template event forward — weekly, every N weeks, monthly on a chosen day, or yearly — and a cron job keeps the future filled. Visitors get an archive split into upcoming and past, a single event page, an iCalendar download, an “Add to Google Calendar” link, and Event structured data. Organizers get CSV and JSON import, bulk edits across a series, and a default image per series.

The working programme already runs in production inside a custom theme. This repository is that programme as a plugin other sites can install, configure, and extend. It has been public for the whole extraction. Version 0.2.0 registers the event type, venues, series, recurrence, import, and the public templates. The reference theme is still the copy that serves its own `/events/` archive. Activating both is safe for the theme programme and shows an admin warning, because both want that slug. Phase 9, cutting the reference site over, is not done in this repository.

The model is a list of occurrences, not a month-grid calendar, and it does not sell tickets, take RSVPs, or replace a theme. Ticketing stays an external URL on the event. Themes supply their own templates when they want a different layout.

Stored datetimes are naive site-local strings (`Y-m-d H:i:s`). They are parsed with `wp_timezone()`, because `strtotime()` on a string with no zone follows the server clock and will display a 7pm show as 3pm on a UTC host. That rule stays in every phase.

## Status

| | |
| --- | --- |
| Version | 0.2.0 — installable programme |
| License | [GPL-2.0-or-later](LICENSE) |
| Requires | WordPress 6.2+, PHP 8.0+ |
| Source still in the theme | `grb-custom` theme `inc/grb-events*.php`, `archive-grb_event.php`, `single-grb_event.php`, `css/events.css` |
| Leave in the theme | Venue FAQ content, theme headers, Bootstrap, critical CSS |

Phases 2 through 5 landed as the classes under `includes/`. There is no `includes/legacy/` dump, so this tree never contained a venue list or a show catalogue. The reference theme was left in place on purpose.

A date-only “generate from” value keeps the template’s clock time. The generator steps with `DateTimeImmutable` in the site timezone, so a weekly rule stays at 7pm across a daylight-saving change. Uninstall clears the cron hook and the rewrite-flush flag. It does not delete events, series, or venues.

## Hooks

Filters added on `init` before priority 5 still apply to registration. Later filters apply to the next request.

| Hook | Arguments | Default |
| --- | --- | --- |
| `wvm_post_type` | `string $slug` | `venue_event` |
| `wvm_archive_slug` | `string $slug` | `events` |
| `wvm_event_meta_keys` | `array $keys` purpose => meta key | `_venue_event_*` |
| `wvm_venues` | `array $venues` each `term_id`, `slug`, `name`, `label`, `address` | stored `venue_place` terms |
| `wvm_recurrence_horizon_months` | `int $months` | `6` |
| `wvm_generated_event_postarr` | `array $postarr`, `WP_Post $template`, `int $series_term_id` | title, content, excerpt, and a unique slug from the template |
| `wvm_generated_event_status` | `string $status`, `WP_Post $template`, `int $series_term_id` | `publish` |
| `wvm_import_row` | `array $row`, `int $index` | the CSV/JSON row |
| `wvm_schema` | `array $schema`, `int $post_id` | Schema.org `Event` |
| `wvm_ics` | `string $ics`, `int $post_id` | one `VEVENT` |
| `wvm_enqueue_styles` | `bool $enqueue` | `true` |

| Action | Arguments |
| --- | --- |
| `wvm_event_generated` | `int $post_id`, `int $series_term_id`, `int $template_id` |
| `wvm_series_populated` | `int $created`, `int $series_processed` |
| `wvm_events_imported` | `array $result` with `created`, `updated`, `skipped`, `trashed`, `errors` |

`grb_event_venues` still runs after `wvm_venues`, in the old slug => `{name, label, address}` shape. `grb_event_series_match_patterns` still runs after the patterns stored on series terms. Both are deprecated.

A small must-use plugin can change the horizon and the generated status without editing this plugin:

```php
add_filter( 'wvm_recurrence_horizon_months', function () {
    return 3;
} );

add_filter( 'wvm_generated_event_status', function () {
    return 'draft';
} );
```

## Development roadmap

Work the phases in order. Each phase should leave a reference site that already uses the theme programme able to keep serving `/events/` until the cutover phase. This remote stays public, so site lists (venue names, series names, alias maps, brand images) are stripped in the same commit that introduces the code. Push the generalized shape, and keep a local compatibility shim for the reference site until phase 8.

Tick a box here when the phase is on `main`.

### Phase 1 — Draw the boundary

- [x] Treat these files as the programme. They move:
  - `inc/grb-events.php` — post type, meta, venues, series matching, ICS, Google Calendar, JSON-LD, template routing
  - `inc/grb-events-series-management.php` — recurrence, cron, bulk update, the long admin screen
  - `inc/grb-events-series-images.php` — default image per series
  - `inc/grb-events-import.php` — CSV and JSON import
  - `inc/grb-acf-events.php` — optional ACF field group that syncs venue and ticket URL into post meta
  - `archive-grb_event.php`, `single-grb_event.php`, `template-parts/components/event-card.php`, `css/events.css`
- [x] Leave these out of the plugin. They are one site’s history or content:
  - `inc/grb-events-migrate.php` — one-shot import from The Events Calendar (`tribe_events`)
  - `inc/grb-venue-faqs.php` and `inc/grb-acf-venue-faqs.php` — page copy, not the programme
  - `mu-plugins/grb-events-export.php` — exporter for The Events Calendar occurrences. Useful as a private migration aid, not as a feature of the installed plugin
  - `grb_redirect_old_event_urls()` in `grb-events.php` — 301 from `/event/{slug}/` to `/events/{slug}/` for that launch
- [x] Note the loaders at the bottom of `grb-events.php` (`grb_events_load_migrate`, `_import`, `_series_images`, `_series_management`) and the `require` in the theme `functions.php`. Phase 8 removes those requires so the theme and the plugin never both register `grb_event`.

**Done when:** the file list above is agreed, and nothing outside it is staged in this repo.

### Phase 2 — Lift the code, then delete the site data

- [x] Copy the phase 1 files under this plugin. Point `get_template_directory()` and `get_theme_file_uri()` at `WVM_PATH` and `WVM_URL`. The default image helper currently returns `get_theme_file_uri( '/img/GR-DefaultImage.jpg' )`. Drop that file. An event with no featured image and no series image renders no image.
- [x] Change the text domain from `goodrobot` to `wordpress-venue-management`.
- [x] Before the first commit that contains PHP from the theme, remove or empty these literals:
  - `grb_event_get_venues()` — Robie Street, Commons, and Elmsdale, with street addresses
  - `grb_event_get_series_match_patterns()` — title substrings mapped to named shows
  - `grb_events_series_get_canonical_list()` and `grb_events_series_get_alias_map()` — the prune-to-canonical tool that deletes every series term not on that list
  - ICS `PRODID:-//Good Robot//Events//EN`
- [x] Keep the existing filters (`grb_event_venues`, `grb_event_series_match_patterns`) only as temporary wrappers. Phase 4 replaces them with stored venues and series. An empty default must still run: no venues configured means no venue rewrite rules, and the archive lists every event.
- [x] Do not load `grb-events-migrate.php`. The prune-to-canonical and “apply series by title” actions are operator tools for one catalogue. They do not ship as buttons that can unassign another site’s series.

**Done when:** the plugin contains the programme, a clean tree has no venue street addresses and no show names, and the theme on the reference site is still the copy that serves production.

### Phase 3 — Give it a stable public API

The theme prefixes everything `grb_`. Other developers need names that describe the feature, plus a way for the reference site to keep reading old rows.

- [x] Post type default: `venue_event`. Archive slug default: `events`. Both filterable before `init`.
- [x] Taxonomy default: `venue_series`, non-public, shown in the admin and the REST API, as `grb_series` is today.
- [x] Meta keys, one string each, registered with `register_post_meta` and an `auth_callback` of `edit_post`:

  | Purpose | Theme key today | Plugin key |
  | --- | --- | --- |
  | Start | `_grb_event_start` | `_venue_event_start` |
  | End | `_grb_event_end` | `_venue_event_end` |
  | Cost label | `_grb_event_cost` | `_venue_event_cost` |
  | Venue name | `_grb_event_venue_name` | `_venue_event_venue_name` |
  | Venue address | `_grb_event_venue_address` | `_venue_event_venue_address` |
  | Ticket URL | `_grb_event_ticket_url` | `_venue_event_ticket_url` |
  | Series label cache | `_grb_event_series` | `_venue_event_series` |

- [x] Series recurrence config moves from term meta `_grb_series_recurrence_config` to `_venue_series_recurrence`.
- [x] Add an admin tool, run once, that copies `grb_event` posts and `grb_series` terms onto the new names when those old keys exist. Reads fall back to the old key until the copy has run, so a half-migrated site still renders dates.
- [x] Flush rewrites on activation and deactivation, not on every `init`. Delete the “flush once via an option” pattern (`grb_events_rewrite_flushed`, `grb_events_venue_rewrite_flushed`).

**Done when:** a fresh site can create an event under the new names, and a site with old `grb_event` rows can copy them without losing start times.

### Phase 4 — Venues and series are content

- [x] Store venues as their own records (a small custom post type, or a taxonomy with address on the term). Fields: name, slug, public label, address. The archive URL `/{archive}/{venue-slug}/` is generated from those records. Adding a room does not require a code deploy.
- [x] Match an event to a venue by ID, and keep the address string on the event only when someone picks “Other”.
- [x] Series are the `venue_series` terms. Title-to-series matching, if it stays at all, reads patterns from term meta that an editor sets. There is no canonical list in PHP.
- [x] Series default images stay an attachment ID on the term, which `grb-events-series-images.php` already does with an option keyed by series name. Key the image by term ID so a rename does not drop the picture.
- [x] ACF stays optional. When Advanced Custom Fields is present, the field group in `grb-acf-events.php` can register and must write the same meta the meta box writes. When it is absent, the plugin meta box is enough. Do not require ACF to save a show.

**Done when:** a second site can add two venues and a series from wp-admin and see `/events/{slug}/` filter to that venue, with no PHP edit.

### Phase 5 — Split the files the way the behaviour already splits

`grb-events-series-management.php` is about 1,100 lines, and most of that is one admin page. `grb-events.php` is about 900. Break them into classes the bootstrap requires, each with one job:

| Class | Moves out of |
| --- | --- |
| `Post_Type` | `grb_register_event_post_type`, admin columns, sort by start via `posts_clauses` |
| `Meta` | `grb_register_event_meta`, meta box, `grb_event_parse_local_datetime` |
| `Venues` | `grb_event_get_venues`, rewrite tag, query var, archive title |
| `Series` | taxonomy, term sync to the label cache, default image |
| `Recurrence` | `grb_events_series_generate_recurring`, `grb_events_series_get_next_occurrence_date`, `grb_events_series_auto_populate`, the weekly cron |
| `Importer` | `grb-events-import.php` |
| `Feeds` | ICS download, Google Calendar URL, JSON-LD |
| `Templates` | archive, single, card, stylesheet |
| `Admin` | series management UI, bulk field copy, image picker |

Preserve the recurrence behaviour while splitting it. It is the part other developers cannot get from a generic calendar:

- Rules: `weekly`, `every_n_weeks` (interval 1–12), `monthly` (day 1–31, clamped to the month length), `yearly`.
- The template’s start and end define the clock time and the duration. A missing end becomes one hour.
- Generation skips a start string that already exists in the series (first 19 characters).
- Caps today: 26 weeks, 6 months, and a horizon of `GRB_SERIES_RECURRENCE_HORIZON_MONTHS` (6). An interval of 6 months or more creates a single occurrence per run (`GRB_SERIES_RECURRENCE_LONG_INTERVAL_MONTHS`).
- Config is stored on the series term. `grb_events_series_auto_populate()` finds the latest start in the series and generates the next ones. Cron hook `grb_events_series_cron_populate` is weekly, scheduled from `admin_init`. Move scheduling to activation, and clear it on deactivation. Do not depend on someone opening wp-admin.
- Slugs today are `{series}-{Y-m-d}-{timestamp}`. Keep them unique and filterable.
- New posts copy title, content, excerpt, cost, venue, and ticket URL from the template. They publish immediately. Make the status filterable so a site can generate drafts.

Replace `strtotime( '+1 week', $cursor_ts )` inside the generator with `DateTimeImmutable` in the site timezone, matching `grb_events_series_get_next_occurrence_date()`. The two functions must agree across a DST change.

**Done when:** recurrence lives in one class with the same caps and the same skip-duplicates rule, and the admin screen only calls it.

### Phase 6 — A documented import, and an export that matches it

`grb-events-import.php` is about 1,100 lines and knows about this catalogue: parent slugs from occurrence slugs, a placeholder ticket-URL repair, and “trash anything not in the file.” Those last two are dangerous as defaults.

- [x] Publish one CSV (and the same columns as JSON) that round-trips:

  `title`, `slug`, `start`, `end`, `cost`, `venue_slug`, `address`, `ticket_url`, `series`, `excerpt`, `content`, `image_url`

  `start` and `end` are site-local `Y-m-d H:i:s`.
- [x] Match existing events by slug, then by title plus start date, which the importer already does. Default to update-in-place. Creating duplicates is an explicit choice.
- [x] “Trash events missing from this file” is off unless the request passes a confirm flag. It must never run on a partial file.
- [x] Sideloading `image_url` stays optional.
- [x] Ship a sample CSV in `samples/` with fictional venues. No real shows.
- [x] Export from this plugin writes that same format. The old The Events Calendar exporter stays out of the package.

**Done when:** the sample CSV imports on a fresh site, a second import updates those rows, and nothing is trashed.

### Phase 7 — Templates a theme can replace

The archive is two queries: upcoming (`_grb_event_start >= today`, soonest first) and past (before today, newest first), plus a venue meta clause and a search string. The single template is an event card with cost, ticket link, calendar links, and schema. Both templates call `get_header( 'events' )` and assume the theme’s Bootstrap layout and `css/events.css`.

- [x] Ship plain `templates/archive-venue-event.php` and `templates/single-venue-event.php` that use the theme’s normal header and footer.
- [x] Resolve templates in this order: stylesheet directory, template directory, then the plugin. A theme file named `venue-event/archive.php` or `venue-event/single.php` wins.
- [x] Keep the upcoming/past split and the venue query as template tags (`wvm_get_upcoming_events()`, `wvm_get_past_events()`) so a theme can build its own markup and still share the query.
- [x] Enqueue `assets/venue-events.css` only on the archive and singular. Provide `wvm_enqueue_styles` defaulting to true so a theme can turn the stylesheet off.
- [x] Keep search-on-the-archive: a search whose path is under the events archive uses the events template, the “Events” document title, and the archive body classes. That logic is `grb_events_search_use_archive_template` and its two neighbours. Point it at the plugin template, not `get_template_directory() . '/archive-grb_event.php'`.

**Done when:** a default theme shows upcoming and past events with no brand CSS, and a child theme can override the single template by dropping one file in place.

### Phase 8 — Hooks other plugins can rely on

Name the new hooks in a `docs` comment block on the class that fires them, and list them in this README when they exist. Minimum set:

**Filters**

- `wvm_post_type` and `wvm_archive_slug` — register-time identifiers
- `wvm_event_meta_keys` — the key map, so a compatibility layer can add the old `grb_` keys
- `wvm_venues` — only if a site still wants to inject venues in code; stored venues remain the default
- `wvm_recurrence_horizon_months` — default 6
- `wvm_generated_event_postarr` — the `wp_insert_post` array for one occurrence
- `wvm_generated_event_status` — default `publish`
- `wvm_import_row` — one associative row before it is written
- `wvm_schema` — the JSON-LD array
- `wvm_ics` — the iCalendar string

**Actions**

- `wvm_event_generated` — `( int $post_id, int $series_term_id, int $template_id )`
- `wvm_series_populated` — after a cron or manual populate, with the created count
- `wvm_events_imported` — after a file finishes, with created and updated counts

Keep a deprecated alias for each `grb_event_*` filter that already exists, calling the new hook, until the reference theme stops using them.

**Done when:** the README lists every public hook with its arguments, and a small mu-plugin can change the horizon and the generated post status without editing this plugin.

### Phase 9 — Cut the reference site over

- [ ] On a staging copy, run the phase 3 copier. Compare event counts, the next month of start times, series term counts, and one ICS file against production.
- [ ] Activate this plugin. Remove the `require` of `inc/grb-events.php` and `inc/grb-acf-events.php` from the theme `functions.php`, and stop the theme enqueue of `css/events.css` for events.
- [ ] Confirm `/events/`, a venue subpath, a single event, ICS, JSON-LD, a manual “populate series,” and the weekly cron event `wvm_populate_series` (the renamed cron hook).
- [ ] Leave the theme files in place for one release, unused, then delete them in a later theme commit. Deleting them in the same hour as activation makes a rollback a file restore.

**Done when:** staging serves the programme from the plugin only, and the theme no longer registers `grb_event`.

### Phase 10 — Make it maintainable in public

- [x] `readme.txt` in the WordPress.org style, kept in step with this file: description, installation, a short FAQ, changelog.
- [x] PHP files follow the WordPress PHP documentation standard on every public method.
- [x] Uninstall does not delete events, series, or venues. Document that a site owner deletes content from wp-admin.
- [x] No sample data from a real venue. No API keys. The `.gitignore` already ignores `.env` and `wp-config.php`.
- [x] Tag `0.2.0` when phases 3 through 7 are done and a fresh site can create a series, generate a month of dates, and theme the single template.
- [ ] Tag `1.0.0` after the reference-site cutover has run for a full cron cycle.

## Layout this repo is heading toward

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

Themes replace a template with `venue-event/archive.php`, `venue-event/single.php`, or `venue-event/event-card.php`. Queries are `wvm_get_upcoming_events()` and `wvm_get_past_events()`. `wvm_render_event_card()` prints one card.

## License

[GPL-2.0-or-later](LICENSE). WordPress plugins that run inside WordPress are distributed under the GPL.
