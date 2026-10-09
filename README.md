# Send It

A Statamic addon for sending entries out through pluggable **channels**. Pick
an entry in the control panel, run the **Send It** action, choose a channel,
and go.

The first channel creates a **Mailchimp** campaign from the entry. A built-in
**mailer** channel sends the entry through Laravel's configured mailer so you
can test-send before committing to a real campaign. The channel layer is
designed so additional providers — Postmark lists, SMS, WhatsApp — can be added
without touching the action.

## Installation

```bash
composer require abigah/send-it
```

Publish the config (optional):

```bash
php artisan vendor:publish --tag=send-it-config
```

## Configuration

Set the relevant environment variables:

```dotenv
# Default channel used when none is chosen
SEND_IT_CHANNEL=mailchimp

# Mailchimp
SEND_IT_MAILCHIMP_API_KEY=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-us21
SEND_IT_MAILCHIMP_AUDIENCE_ID=abc123
SEND_IT_MAILCHIMP_FROM_NAME="Abigah"
SEND_IT_MAILCHIMP_REPLY_TO=hello@abigah.com
# Create drafts by default; flip to send immediately
SEND_IT_MAILCHIMP_SEND_IMMEDIATELY=false

# Mailer (test send) — uses your app's default mailer
SEND_IT_MAILER_TO=you@example.com
```

The Mailchimp server prefix (e.g. `us21`) is derived from the API key suffix
automatically; override it with `SEND_IT_MAILCHIMP_SERVER_PREFIX` if needed.

By default the campaign/email body comes from the entry's `content` field
(its augmented HTML). Change the source field per channel via
`config/send-it.php`.

## Usage

1. In the control panel, select one or more entries in a collection listing.
2. Run the **Send It** action.
3. Choose a channel (Mailchimp or the mailer test send), optionally set a
   subject, and confirm.

For Mailchimp, choose a **Delivery** mode:

- **Save as draft** (default) — create the campaign in Mailchimp for review.
- **Send immediately** — send as soon as the action runs.
- **Schedule for later** — pick a date and time; the send happens then.

The schedule field is shown in **your own timezone**, but the time is stored and
sent in the **site timezone** (`config('app.timezone')`). The
`SEND_IT_MAILCHIMP_SEND_IMMEDIATELY` env var just sets which mode is selected by
default.

### How scheduling works

Scheduling runs on the Laravel side, not Mailchimp's native scheduler:

- The chosen send is recorded in `storage/app/send-it/scheduled-sends.json`
  (configurable via `SEND_IT_SCHEDULE_STORE`).
- The addon registers a `send-it:run-scheduled` command to run **every minute**
  via Laravel's scheduler. Make sure `schedule:run` is in your cron:

  ```
  * * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
  ```

- When a send is due, the command **publishes the entry** (and, for dated
  collections that hide future-dated entries, brings the date forward to now so
  it's live) and then creates and sends the Mailchimp campaign immediately.

Because the campaign is built when the scheduler fires, it always uses the
entry's latest content.

## Email layout

Channel content is wrapped in an Antlers email layout before sending — a
responsive, email-client-safe shell with the logo centered at the top and a
footer (copyright, address, unsubscribe link). Configure it under the `email`
key in `config/send-it.php` (logo URL/width, site name, footer text/address,
unsubscribe URL).

Customise the markup by publishing the view:

```bash
php artisan vendor:publish --tag=send-it-views
# resources/views/vendor/send-it/default-email/layout.antlers.html
```

Point `email.layout` at your own view, or set it to `null` to send content
unwrapped.

## Apple push notifications

The **Apple push notification** channel sends an entry as a push notification
to every app install that has registered with the site. It uses an APNs auth
key, so one key covers iOS, iPadOS, macOS and watchOS. Watches receive the
notification through the paired iPhone.

```dotenv
SEND_IT_APNS_ENABLED=true
SEND_IT_APNS_TEAM_ID=ABCDE12345          # Apple Developer team id
SEND_IT_APNS_KEY_ID=XYZ9876543           # the auth key's id
SEND_IT_APNS_PRIVATE_KEY_PATH=storage/app/apns/AuthKey_XYZ9876543.p8
# …or base64 of the .p8 file (base64 -i AuthKey_XYZ9876543.p8):
# SEND_IT_APNS_PRIVATE_KEY_BASE64=LS0tLS1CRUdJTi…
# …or inline, with \n for newlines:
# SEND_IT_APNS_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\nMIG…\n-----END PRIVATE KEY-----"
SEND_IT_APNS_TOPIC=com.example.app       # the app's bundle id
SEND_IT_APNS_TEST_TOKEN=                 # optional default for test sends
```

Registered devices are kept in a JSON file, keyed by token, at
`storage/app/send-it/push-devices.json` by default. No database is needed. To
have the list backed up with your content, point it at a git-tracked folder:

```env
SEND_IT_APNS_DEVICES_STORE=/path/to/site/content/send-it/push-devices.json
```

Writes are locked, so concurrent registrations are safe. A device is only
rewritten when something changes (`last_seen` is a date), so the file doesn't
churn in git.

**Registering devices.** Apps register by POSTing JSON to `/api/push/devices`
(`token`, plus optional `platform`, `environment` (`sandbox` or `production`),
`app_version`, `locale` and `timezone`). To unregister, send
`DELETE /api/push/devices/{token}`. The route is throttled. Change or disable it
with `send-it.channels.apns.devices.route`.

**Sending.** Choose **Apple push notification** in the Send It action. The
title is the Subject, or the entry title if Subject is empty. The message is
what you type; if you leave it blank, it comes from the entry's `excerpt` field
(`SEND_IT_APNS_BODY_FIELD`), or from the start of its content. **When tapped**
can open the entry's page, which is sent as a `url` key in the payload. It can
also send an app-specific `action`, configured under
`send-it.channels.apns.actions`. There are three delivery options:

- **Test: send to one device.** Sends right away to one token and shows
  Apple's response.
- **Send to all devices now.** Queues a job that sends to every registered
  device in parallel batches. Devices that Apple reports as gone
  (`Unregistered`, `BadDeviceToken`) are removed.
- **Schedule for later.** Uses the same scheduler as Mailchimp. The entry is
  published when the send goes out.

Devices built from Xcode register as `sandbox`. TestFlight and App Store builds
register as `production`. Each device is sent to the matching APNs server.

## Adding a channel

Implement `Abigah\SendIt\Contracts\Channel` and register it from any service
provider:

```php
app(\Abigah\SendIt\Channels\ChannelManager::class)
    ->extend('postmark', fn ($app) => new PostmarkChannel(config('send-it.channels.postmark')));
```

Configured channels automatically appear in the **Send It** action's channel
picker.

## License

MIT
