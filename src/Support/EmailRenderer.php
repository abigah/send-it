<?php

namespace Abigah\SendIt\Support;

/**
 * Wraps channel content in the configured Antlers email layout (logo, footer,
 * etc.). When no layout is configured the content is returned unchanged.
 */
class EmailRenderer
{
    /**
     * @param  array<string, mixed>  $config  The "email" config block.
     */
    public function __construct(
        protected array $config = [],
    ) {
    }

    /**
     * Render the email body for the given subject and content HTML.
     *
     * @param  array<string, mixed>  $overrides  Per-send variable overrides.
     */
    public function render(string $subject, string $content, array $overrides = []): string
    {
        $layout = $this->config['layout'] ?? null;

        if (empty($layout) || ! view()->exists($layout)) {
            return $content;
        }

        return (string) view($layout, array_merge([
            'subject' => $subject,
            'preheader' => $subject,
            'content' => $content,
            'title' => '',
            'authors' => '',
            'date' => null,
            'greeting' => null,
            'logo_url' => $this->config['logo_url'] ?? null,
            'logo_width' => $this->config['logo_width'] ?? 160,
            'site_name' => $this->config['site_name'] ?? config('app.name'),
            'site_url' => $this->config['site_url'] ?? config('app.url'),
            'company' => $this->config['company'] ?? $this->config['site_name'] ?? config('app.name'),
            'footer_text' => $this->config['footer_text'] ?? null,
            'footer_address' => $this->config['footer_address'] ?? null,
            'unsubscribe_url' => $this->config['unsubscribe_url'] ?? '#',
            'update_preferences_url' => $this->config['update_preferences_url'] ?? null,
            'year' => date('Y'),
        ], $overrides));
    }
}
