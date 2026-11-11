# Changelog

All notable changes to this project are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/); versioning is
[Semantic Versioning](https://semver.org/), with the common `telegram-bot-essentials/*`
convention that a 0.x minor may carry breaking changes until the package's API stabilizes at 1.0.

## [Unreleased]

## [0.0.2] - 2026-11-11

### Added

- Add ticket file serving for the screens.
- Add the ticket thread views.
- Add english screen strings.
- Add persian screen strings.
- Register the support panel plugins.
- Add the support plugins for both panels.
- Share the ticket thread between screens.
- Add the customer ticket pages.
- Add the manage ticket resource.
- Upload a panel file to telegram for a ticket.
- Word ticket attachments in english.
- Word ticket attachments in persian.
- Attach a file to a ticket reply.
- Add empty-list messages.
- Group, date and tidy the panel screens.
- Find tickets from the sidebar search.
- Show the ticket customer as facts and dates in the viewer's calendar.
- Let the record number be copied from the list.

### Changed

- Show the ticket thread for staff only.
- Register only the manage plugin.
- Remove the customer panel plugin.
- Remove the shared ticket thread concern.
- Remove the customer ticket pages.
- Fold the ticket thread into the staff view.

### Fixed

- Title search results from the ticket's own id.
- Send ticket replies in the bot's language.

## [0.0.1] - 2026-11-03

### Added

- Tickets between customers and staff: threads of text, photos and documents,
  with `open`, `answered` and `closed` statuses. Either side replies with a button
  or by replying to a message natively.
- A contact screen, `SupportKey` and `/support`, offering tickets, free-form
  contact handles, or both.
- Staff tickets list with tabs, `TicketsKey`, behind the `support.manage` ability.
- Alerts to the owner and any recipient groups, falling back to the owner.
- Limits on open tickets and message rate, and hourly `tbe:support:close-stale`.
- `ticketContext()`, for an app to add facts about a customer to a ticket.
