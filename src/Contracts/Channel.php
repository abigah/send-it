<?php

namespace Abigah\SendIt\Contracts;

use Abigah\SendIt\Support\SendResult;
use Statamic\Contracts\Entries\Entry;

interface Channel
{
    /**
     * The machine key used to select this channel (e.g. "mailchimp").
     */
    public function key(): string;

    /**
     * The human label shown in the control panel.
     */
    public function label(): string;

    /**
     * Whether this channel is configured and usable.
     */
    public function isConfigured(): bool;

    /**
     * Extra fields this channel contributes to the "Send It" action form,
     * keyed by handle. Returned in Statamic field-config format.
     *
     * @return array<string, array<string, mixed>>
     */
    public function fields(): array;

    /**
     * Send a single entry through this channel.
     *
     * @param  array<string, mixed>  $options  Values from the action form.
     */
    public function send(Entry $entry, array $options = []): SendResult;
}
