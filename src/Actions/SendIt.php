<?php

namespace Abigah\SendIt\Actions;

use Abigah\SendIt\Channels\ChannelManager;
use Abigah\SendIt\Contracts\Channel;
use Abigah\SendIt\Exceptions\SendItException;
use Abigah\SendIt\Support\SendResult;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;

class SendIt extends Action
{
    protected $icon = 'mail-send-email-attachment-document';

    public static function title()
    {
        return __('Send It');
    }

    public function visibleTo($item)
    {
        return $item instanceof Entry && $this->manager()->available() !== [];
    }

    public function authorize($user, $item)
    {
        return $user->can('edit', $item);
    }

    public function buttonText()
    {
        /** @translation */
        return 'Send It|Send {count} entries';
    }

    public function confirmationText()
    {
        /** @translation */
        return 'Send the selected entry?|Send the {count} selected entries?';
    }

    public function fieldItems()
    {
        $channels = $this->manager()->available();

        $fields = [
            'channel' => [
                'type' => 'select',
                'display' => __('Channel'),
                'instructions' => __('Where to send the selected entries.'),
                'options' => collect($channels)->map->label()->all(),
                'default' => $this->manager()->default(),
                'validate' => 'required',
            ],
            'subject_line' => [
                'type' => 'text',
                'display' => __('Subject'),
                'instructions' => __('Defaults to the entry title when left blank.'),
            ],
        ];

        foreach ($channels as $channel) {
            $fields = array_merge($fields, $channel->fields());
        }

        return $fields;
    }

    public function run($items, $values)
    {
        $channel = $this->manager()->channel($values['channel'] ?? null);

        $results = collect($items)
            ->filter(fn ($item) => $item instanceof Entry)
            ->map(fn (Entry $entry) => $this->sendOne($channel, $entry, $values));

        $failures = $results->filter(fn (SendResult $result) => ! $result->success);

        if ($failures->isNotEmpty()) {
            throw new SendItException(
                $failures->map(fn (SendResult $result) => $result->message)->implode(' ')
            );
        }

        return $results->first()?->message ?? __('Sent.');
    }

    protected function sendOne(Channel $channel, Entry $entry, array $values): SendResult
    {
        try {
            return $channel->send($entry, $values);
        } catch (SendItException $e) {
            return SendResult::failure($channel->key(), $e->getMessage(), $entry);
        }
    }

    protected function manager(): ChannelManager
    {
        return app(ChannelManager::class);
    }
}
