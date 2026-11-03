# Changelog

All notable changes to this project are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/); versioning is
[Semantic Versioning](https://semver.org/), with the common `telegram-bot-essentials/*`
convention that a 0.x minor may carry breaking changes until the package's API stabilizes at 1.0.

## [Unreleased]

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
