<?php

namespace Abigah\SendIt\Channels;

use Abigah\SendIt\Contracts\Channel;
use Abigah\SendIt\Exceptions\ChannelNotFoundException;
use Illuminate\Contracts\Container\Container;

class ChannelManager
{
    /**
     * Resolvers for registered channels, keyed by channel key.
     *
     * @var array<string, callable(Container): Channel>
     */
    protected array $resolvers = [];

    /**
     * Resolved channel instances, keyed by channel key.
     *
     * @var array<string, Channel>
     */
    protected array $resolved = [];

    public function __construct(
        protected Container $container,
        protected ?string $default = null,
    ) {
    }

    /**
     * Register a channel by key. The resolver receives the container and
     * must return a Channel instance. Allows third parties to add their
     * own channels (postmark, sms, whatsapp, ...).
     *
     * @param  callable(Container): Channel  $resolver
     */
    public function extend(string $key, callable $resolver): self
    {
        $this->resolvers[$key] = $resolver;
        unset($this->resolved[$key]);

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->resolvers[$key]);
    }

    /**
     * Resolve a channel by key, or the default channel when none is given.
     */
    public function channel(?string $key = null): Channel
    {
        $key = $key ?: $this->default;

        if ($key === null || ! $this->has($key)) {
            throw ChannelNotFoundException::for((string) $key);
        }

        return $this->resolved[$key] ??= ($this->resolvers[$key])($this->container);
    }

    /**
     * All registered channels, keyed by key.
     *
     * @return array<string, Channel>
     */
    public function all(): array
    {
        foreach (array_keys($this->resolvers) as $key) {
            $this->channel($key);
        }

        return $this->resolved;
    }

    /**
     * Channels that are registered and fully configured, keyed by key.
     *
     * @return array<string, Channel>
     */
    public function available(): array
    {
        return array_filter($this->all(), fn (Channel $channel) => $channel->isConfigured());
    }

    public function default(): ?string
    {
        return $this->default;
    }

    public function setDefault(?string $key): self
    {
        $this->default = $key;

        return $this;
    }
}
