<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;

class PushTransport
{
    private ?WebPush $sender = null;

    public function __construct(private readonly ?ClientInterface $client = null) {}

    public function configured(): bool
    {
        $keys = config('dag.web_push');

        return ! empty($keys['subject']) && ! empty($keys['publicKey']) && ! empty($keys['privateKey']);
    }

    public function acceptsEndpoint(string $endpoint): bool
    {
        $url = parse_url($endpoint);
        if (! is_array($url) || ($url['scheme'] ?? null) !== 'https' || ($url['port'] ?? 443) !== 443
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || empty($url['path'])) {
            return false;
        }

        $host = strtolower($url['host'] ?? '');

        return in_array($host, ['fcm.googleapis.com', 'updates.push.services.mozilla.com'], true)
            || str_ends_with($host, '.push.apple.com') || str_ends_with($host, '.notify.windows.com');
    }

    public function send(#[\SensitiveParameter] array $subscription, array $payload, int $ttl): string
    {
        if (! $this->acceptsEndpoint($subscription['endpoint'])) {
            return 'failed';
        }

        $report = $this->sender()->sendOneNotification(
            Subscription::create($subscription),
            json_encode($payload, JSON_THROW_ON_ERROR),
            ['TTL' => $ttl, 'urgency' => 'high'],
        );

        if ($report->isSuccess()) {
            return 'sent';
        }
        if ($report->isSubscriptionExpired()) {
            return 'expired';
        }

        $status = $report->getResponse()?->getStatusCode();

        return $status === 429 || ($status >= 500 && $status <= 599) ? 'retry' : 'failed';
    }

    private function sender(): WebPush
    {
        if ($this->sender === null) {
            $factory = new HttpFactory;
            $this->sender = new WebPush(
                ['VAPID' => config('dag.web_push')],
                client: $this->client ?? new Client(['connect_timeout' => 2, 'timeout' => 4, 'allow_redirects' => false]),
                requestFactory: $factory,
                streamFactory: $factory,
                logger: Log::channel(),
            );
        }

        return $this->sender;
    }
}
