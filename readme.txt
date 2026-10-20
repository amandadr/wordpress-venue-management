=== WordPress Venue Management ===
Contributors: amandadr
Tags: events, venue, calendar, series, ics
Requires at least: 6.2
Tested up to: 6.8
Stable tag: 0.2.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A venue programme of occurrences and series. Each date is its own event, and a saved rule fills the future calendar.

== Description ==

WordPress Venue Management is for venues that run a public programme: a weekly trivia night, a monthly reading, a seasonal popup.

* Each date is its own event.
* Dates that belong together form a series.
* A saved rule copies a template event forward: weekly, every N weeks, monthly on a chosen day, or yearly.
* A weekly cron job keeps the future filled out to a horizon (six months by default).
* Visitors get an archive split into upcoming and past, a single event page, an iCalendar download, an Add to Google Calendar link, and Event structured data.
* Organizers import and export CSV or JSON, copy fields across a series, and set a default image per series.

Venues are their own terms, with a public label and an address. The archive can filter to `/{archive}/{venue-slug}/`. Adding a room does not require a code change.

Stored datetimes are site-local `Y-m-d H:i:s` strings, parsed with `wp_timezone()`. Ticket sales stay an external URL on the event.

Themes replace `templates/archive-venue-event.php`, `templates/single-venue-event.php`, and `templates/event-card.php` by adding `venue-event/archive.php`, `venue-event/single.php`, or `venue-event/event-card.php`. Turn the bundled stylesheet off with the `wvm_enqueue_styles` filter.

Advanced Custom Fields is optional. When it is active, venue and ticket fields also appear in an ACF group and write the same meta.

If a theme still registers its own `grb_event` archive at `/events/`, the plugin shows an admin warning. Events → Migrate legacy programme copies those posts and series terms and leaves the originals in place.

Full hook list: see the repository README.

Planned after 0.2.0: an iCalendar feed for a venue or series, cancelling a single date, monthly rules by weekday, WP-CLI commands, and block theme templates.

== Installation ==

1. Upload the plugin directory to `/wp-content/plugins/`, or install the zip from the plugin screen.
2. Activate WordPress Venue Management.
3. Add venues under Events → Venues, and a series under Events → Series.
4. Create one event with a start time, assign the series, then open Events → Series tools, save a rule, and generate dates.
5. Visit the events archive. The default slug is `/events/`.

== Frequently Asked Questions ==

= Does uninstall delete my events? =

No. Uninstall clears the weekly cron hook and a rewrite flag. Delete events, series, and venues from wp-admin if you want them gone.

= Can I import a spreadsheet? =

Yes. Events → Import & export accepts CSV or JSON with these columns: title, slug, start, end, cost, venue_slug, address, ticket_url, series, excerpt, content, image_url. A second import updates matching rows. Trashing events that are missing from the file stays off unless an administrator checks both confirm boxes. A fictional sample CSV is included.

= Do I need Advanced Custom Fields? =

No. The event meta box edits the start, end, cost, venue, and ticket URL on its own.

= How do I change the archive slug or generate drafts? =

Filter `wvm_archive_slug` and `wvm_generated_event_status`. Register those filters before `init` priority 5 when they change the post type or archive slug.

== Changelog ==

= 0.2.0 =
* Events, venues, series, recurrence, feeds, templates, import and export, optional ACF, and a one-time copy from a legacy `grb_event` programme.
* Sample CSV uses fictional venues.

= 0.1.0 =
* Public scaffold and extraction roadmap. No post type yet.
