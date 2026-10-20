# Magenx_AbandonedCheckout

> **Built with AI assistance.** This module is built with significant use of Claude Code, under active human review and guidance. It is open source and in active development. AI assistance does not lower the engineering bar: every change must meet the same correctness, security, documentation and maintainability requirements as any other contribution.
>
> **No warranty.** We are not responsible for any incompatibility. By installing this software, you accept responsibility for its security, upgrades, patches and monitoring.

Emails shoppers a **recovery reminder** when they leave items in their cart and
don't check out. A cron scans active quotes, and for each one that has items, a
captured email, and has sat untouched past a configurable delay, it sends a
reminder listing the cart contents with a one-click link back to the cart.

## How it works

1. **`Cron/SendReminders`** (schedule `magenx_abandoned_checkout/cron/schedule`,
   default `*/15 * * * *`) asks the finder for a batch of candidates and runs
   each through the processor.
2. **`Model/AbandonedCartFinder`** issues a single, index-friendly query over
   `quote`, restricted to store views that actually have reminders switched on.
   The `(is_active, magenx_abandoned_reminder_count, updated_at)` index added in
   `db_schema.xml` mirrors the query exactly — equality, equality, then the
   `updated_at` range plus the `ORDER BY … LIMIT` — so the optimizer stops at the
   batch size instead of scanning the whole time window.
3. **`Model/ReminderProcessor`** applies the exact, **store-scoped** rules,
   **claims** the attempt, loads the quote, re-checks it's still active / has
   items / clears the min total, sends, and only then bumps the success counter.
4. **`Model/ReminderSender`** renders the email **inside the recipient store's
   emulated environment**, so translations and number formatting match the store
   view rather than whatever locale the cron process happens to be in.
5. **`Model/RecoveryToken` + `Model/RecoveryUrl`** build the recovery link.

### Reminder bookkeeping (no extra table)

Two columns are added to `quote` (`db_schema.xml`):

- `magenx_abandoned_notified_at` — when the last reminder was **attempted**,
  successful or not (NULL = never)
- `magenx_abandoned_reminder_count` — how many reminders were **successfully**
  sent

They're written with a **targeted `UPDATE`** (`Model/ResourceModel/Reminder`),
**not** a quote save — a quote save would bump `updated_at` and reset the
abandoned clock, so the cart could never satisfy the max-age / repeat rules.

Both writes are compare-and-swap:

- `claimAttempt()` stamps the attempt only if the row still carries the value the
  candidate query read. Exactly one process can own a given attempt, so a second
  app server or a manual `cron:run` overlapping the scheduler cannot double-send.
- `markNotified()` sets the counter to an expected value rather than
  incrementing it in SQL, so a send that raced cannot be counted twice.

Claiming **before** the send is also what keeps the batch moving. The candidate
query is `ORDER BY updated_at ASC LIMIT n` and only a write moves a row, so
anything that stays a candidate but is rejected downstream sits at the head of
every batch until it ages out. One cart with an unsendable address could
occupy a slot every 15 minutes for a week. Now a failed send leaves a recent
attempt with a zero count, which the finder's spacing filter defers by
**Retry Backoff** (default 60 min) and the processor honours exactly.

## Recovery links: what is in the URL, and why

`quote_id_mask.masked_id` is a **bearer credential** for the cart — whoever
holds it can read and modify it through the guest-cart API. So it is *not* what
the email carries.

Instead `Model/RecoveryToken` mints `base64url("<maskedId>.<expiry>")` plus an
HMAC-SHA256 signature, and the link is `…/{recovery_path}?t=<token>`. The
storefront's `/api/cart/recover` route verifies the signature and the expiry
**server-side** with a shared secret and returns the masked id only then. Two
things follow:

- the cart credential never appears in a URL, a browser history entry, a CDN or
  access log, or any analytics / session-replay tool that records
  `location.href`;
- a forwarded or leaked link stops working once the token expires (**Recovery
  Link Lifetime**, default 72h, capped at Maximum Cart Age).

Tokens are deliberately **not single-use**: corporate link scanners (Microsoft
Safe Links and similar) fetch links before the recipient ever sees them, so a
one-shot token would arrive already spent for a large share of real inboxes.

**Signed-in customers' carts get a link with no token at all.** This module does
not mint a `quote_id_mask` for a customer quote. Magento's REST guest-cart
endpoints resolve a mask to a quote without checking that no customer is
attached, so mailing a mask for a logged-in customer's cart can hand whoever
holds the link that customer's cart — including the name, email and addresses
saved on it. They don't need one: `customerCart` resolves their cart from their
session when they land on the storefront.

### Setup

Set **Recovery Link Secret** (Magenx → Abandoned Checkout Reminder → Email) to
at least 32 random characters, and give the storefront the same value as
`MAGENX_ABANDONED_RECOVERY_SECRET`. **Until both sides have it, guest-cart
reminders are not sent** — signing raises, and the cart is retried after the
backoff. Customer-cart reminders still go out, because their link carries no
token to sign.

## Storefront recovery route

The email link points at the Next.js storefront route
`apps/theme/src/app/[locale]/cart/recover/page.tsx`. It strips the token from
the address bar, exchanges it at `/api/cart/recover`, and adopts the returned
cart id through `CartProvider`'s `adoptCart`.

Restoring **replaces** whichever cart the browser is holding, so when that cart
already has items the shopper is **asked first** rather than having it swapped
out from under them — otherwise a cart built after the email went out is thrown
away silently, and on a shared device they inherit someone else's.

Because each locale maps 1:1 to a Magento store view, set the **Storefront
Recovery Path** per store view if your locale is part of the URL path (e.g.
`de/cart/recover`). The default `cart/recover` relies on the store base URL /
storefront middleware to resolve the locale.

## Configuration

**Stores → Configuration → Magenx → Abandoned Checkout Reminder**

| Field | Config path | Default |
| --- | --- | --- |
| Enabled | `magenx_abandoned_checkout/general/enabled` | No |
| Delay before first reminder (min) | `…/general/delay_minutes` | 60 |
| Maximum cart age (hours) | `…/general/max_age_hours` | 168 |
| Maximum reminders per cart | `…/general/max_reminders` | 1 |
| Min interval between reminders (min) | `…/general/repeat_interval_minutes` | 1440 |
| Retry backoff after a failed send (min) | `…/general/retry_backoff_minutes` | 60 |
| Batch size per cron run | `…/general/batch_size` | 50 |
| Max reminders per email address | `…/general/max_reminders_per_email` | 3 |
| Per-address cap window (days) | `…/general/per_email_window_days` | 30 |
| Minimum cart total | `…/general/min_subtotal` | 0 |
| Email template | `…/email/email_template` | `magenx_abandoned_checkout_email_email_template` |
| Email sender | `…/email/sender_email_identity` | `sales` |
| Storefront recovery path | `…/email/recovery_path` | `cart/recover` |
| Recovery link secret | `…/email/recovery_secret` | *(none — required)* |
| Recovery link lifetime (hours) | `…/email/token_ttl_hours` | 72 |
| Storefront unsubscribe path | `…/email/unsubscribe_path` | *(empty)* |
| Cron schedule | `…/cron/schedule` | `*/15 * * * *` |

Off by default. Everything except batch size and the cron schedule is store-view
scoped, so store views behave independently — and the candidate query derives its
SQL bounds from the **most permissive** value in use across the enabled stores,
so a store view that shortens the delay, lengthens the max age or raises the
reminder cap is not silently starved by the default-scope value.

## Per-recipient cap

**Maximum Reminders Per Email Address** (default 3, over a 30-day window) bounds
what any one address can receive across *all* carts, so minting more carts stops
helping. `Model/RecipientQuota` primes it with one grouped query per cron run and
keeps the tally in memory as the run sends — a batch that is entirely the same
address is caught within that run, not only on the next one. Counts are global
rather than per store view, and the setting is default-scope only, because a
per-store cap would just be spread across store views.

Two things to know:

- It is **conservative by design**. The count sums `reminder_count` for quotes
  whose last attempt falls in the window, so with `max_reminders > 1` an older
  reminder on the same quote can be counted as if it were recent. That errs
  toward withholding, which is the right direction here.
- It backs a capped cart off rather than dropping it. The attempt is claimed
  first, so a capped address returns on the retry backoff instead of every tick,
  and the reminder is not lost — the cart is still a candidate once the window
  frees up.

Set it to **0** to disable the cap entirely. Don't, unless something upstream is
already doing this job.

`db_schema.xml` adds `(customer_email, magenx_abandoned_notified_at)` to back the
lookup; without it the grouped query is a full scan of `quote` every run.

## Notes / limits

- Guest carts only get a reminder once they have a `customer_email` on the quote
  (typically captured at the email/shipping step). Logged-in customers always
  have one.
- **That address is never verified**, and on a headless storefront
  `setGuestEmailOnCart` is a public mutation, so anyone can mint carts addressed
  to someone else and have your store mail them. Per cart that is bounded — one
  reminder, after the delay — but the number of carts is not, which is what makes
  a single address unboundedly spammable. See the cap below.
- Still your job before pointing this at real traffic: set **Storefront
  Unsubscribe Path** and honour it, and feed bounces / complaints into a
  suppression list. These are unsolicited commercial emails and most
  jurisdictions require a working opt-out; the module renders the link when the
  path is set, but nothing here enforces the opt-out.
- Delivery is **at-least-once**. The attempt is claimed before the send, so a
  crash between the send and the counter write leaves a reminder that was
  delivered but not counted; it can go out again after the backoff.
- A store view with a *longer* delay than its siblings still contributes rows
  that the processor rejects as "not due yet". Those are bounded — they become
  due — but they do consume batch slots in the meantime. Raise **Batch Size** if
  your store views' delays differ widely.

## Install

```
bin/magento module:enable Magenx_AbandonedCheckout
bin/magento setup:upgrade   # creates the two quote columns + index
```
