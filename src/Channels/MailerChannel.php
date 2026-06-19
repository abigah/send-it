<?php

namespace Abigah\SendIt\Channels;

use Abigah\SendIt\Contracts\Channel;
use Abigah\SendIt\Exceptions\SendItException;
use Abigah\SendIt\Support\EmailRenderer;
use Abigah\SendIt\Support\EntryContent;
use Abigah\SendIt\Support\SendResult;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Statamic\Contracts\Entries\Entry;

/**
 * Sends an entry through Laravel's configured mailer. Useful for previewing
 * or test-sending an entry before pushing it to a real campaign provider.
 */
class MailerChannel implements Channel
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected array $config,
        protected EmailRenderer $renderer,
    ) {
    }

    public function key(): string
    {
        return 'mailer';
    }

    public function label(): string
    {
        return 'Email (test send)';
    }

    public function isConfigured(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    public function fields(): array
    {
        return [
            'mailer_to' => [
                'type' => 'text',
                'display' => 'Send to',
                'instructions' => 'Email address(es) for the test send, comma separated. Leave blank to use the configured default.',
                'if' => ['channel' => 'equals mailer'],
            ],
        ];
    }

    public function send(Entry $entry, array $options = []): SendResult
    {
        $recipients = $this->recipients($options['mailer_to'] ?? null);

        if (empty($recipients)) {
            throw new SendItException('No recipient configured for the test send.');
        }

        $subject = $options['subject_line'] ?? EntryContent::title($entry);
        $html = EntryContent::html($entry, $this->config['content_field'] ?? 'content');

        if ($html === '') {
            throw new SendItException("Entry [{$entry->id()}] has no content to send.");
        }

        $html = $this->renderer->render($subject, $html);

        Mail::html($html, function (Message $message) use ($recipients, $subject) {
            $message->to($recipients)->subject($subject);

            if (! empty($this->config['from_address'])) {
                $message->from($this->config['from_address'], $this->config['from_name'] ?? null);
            }
        });

        return SendResult::success(
            $this->key(),
            'Sent test email to '.implode(', ', $recipients).'.',
            $entry,
            ['recipients' => $recipients],
        );
    }

    /**
     * @return array<int, string>
     */
    protected function recipients(?string $override): array
    {
        $value = $override ?: ($this->config['to'] ?? null);

        if (empty($value)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
