<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Drivers;

use Glueful\Extensions\Conversa\Support\DriverResult;
use Glueful\Extensions\Conversa\Support\OutboundMessage;
use Glueful\Http\Client;

final class TwilioDriver implements ConversaDriver
{
    /**
     * @param array<string,mixed> $config sid, token, sms_from, whatsapp_from
     * @param array<string,array<string,mixed>> $templates Logical name => per-driver identity
     */
    public function __construct(
        private readonly Client $http,
        private readonly array $config,
        private readonly array $templates,
    ) {
    }

    public function getName(): string
    {
        return 'twilio';
    }

    public function supports(string $channel): bool
    {
        return in_array($channel, ['sms', 'whatsapp'], true);
    }

    public function isAvailable(string $channel): bool
    {
        $base = (bool) ($this->config['enabled'] ?? false)
            && ($this->config['sid'] ?? null) !== null
            && ($this->config['token'] ?? null) !== null;
        if (!$base) {
            return false;
        }

        // Channel-aware: require the sender configured for THIS channel, so a
        // Twilio account set up for SMS only doesn't report the whatsapp channel
        // as available (which would fail at send time).
        return $channel === 'whatsapp'
            ? ($this->config['whatsapp_from'] ?? null) !== null
            : ($this->config['sms_from'] ?? null) !== null;
    }

    public function send(OutboundMessage $message): DriverResult
    {
        $isWhatsapp = $message->channel === 'whatsapp';
        $from = $isWhatsapp ? ($this->config['whatsapp_from'] ?? null) : ($this->config['sms_from'] ?? null);
        $to = $isWhatsapp ? 'whatsapp:' . $message->to : $message->to;

        if ($from === null) {
            return DriverResult::failed("Twilio: no 'from' configured for {$message->channel}.");
        }

        $form = ['From' => $from, 'To' => $to];

        if ($message->isTemplate()) {
            $tpl = $message->template;
            $contentSid = $tpl['provider_ref'] ?? ($this->templates[$tpl['name']]['twilio']['content_sid'] ?? null);
            if ($contentSid === null) {
                return DriverResult::failed("No twilio ContentSid mapping for template '{$tpl['name']}'.");
            }
            $form['ContentSid'] = $contentSid;
            $vars = $tpl['variables'] ?? [];
            if ($vars !== []) {
                // Twilio expects a JSON map of {"1":"x","2":"y"}.
                $map = [];
                foreach (array_values($vars) as $i => $v) {
                    $map[(string) ($i + 1)] = (string) $v;
                }
                $form['ContentVariables'] = json_encode($map, JSON_THROW_ON_ERROR);
            }
        } else {
            $form['Body'] = (string) $message->body;
        }

        try {
            $resp = $this->http->post(
                sprintf('https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', $this->config['sid']),
                [
                    // Glueful Http\Client passes auth_basic through to Symfony
                    // HttpClient (framework 1.49.0), so use the natural API;
                    // form_params becomes the url-encoded body + content-type.
                    'auth_basic' => [$this->config['sid'], $this->config['token']],
                    'form_params' => $form,
                ]
            );
            $data = $resp->json();
            if (!$resp->isSuccessful()) {
                return DriverResult::failed('twilio_http_' . $resp->getStatusCode(), is_array($data) ? $data : []);
            }

            return DriverResult::ok($data['sid'] ?? null, is_array($data) ? $data : []);
        } catch (\Throwable $e) {
            return DriverResult::failed('twilio_exception: ' . $e->getMessage());
        }
    }
}
