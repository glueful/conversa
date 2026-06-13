<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa;

use Glueful\Extensions\Conversa\Drivers\DriverManager;
use Glueful\Extensions\Conversa\Events\MessageFailed;
use Glueful\Extensions\Conversa\Events\MessageSent;
use Glueful\Extensions\Conversa\Repositories\MessageRepository;
use Glueful\Extensions\Conversa\Support\DriverResult;
use Glueful\Extensions\Conversa\Support\OutboundMessage;
use Psr\Log\LoggerInterface;

/**
 * The single synchronous send pipeline: validate -> idempotency check -> log row
 * (queued) -> resolve driver -> send -> record result -> dispatch event.
 */
final class ConversaService
{
    /** @var callable(object):void */
    private $dispatch;

    /**
     * @param array<string,mixed> $features
     * @param callable(object):void $dispatch PSR-14-style event sink (framework EventService::dispatch in prod)
     */
    public function __construct(
        private readonly DriverManager $drivers,
        private readonly MessageRepository $repository,
        private readonly array $features,
        callable $dispatch,
        private readonly LoggerInterface $logger,
    ) {
        $this->dispatch = $dispatch;
    }

    /**
     * @param array{body?:string,template?:array<string,mixed>} $payload
     * @param array{idempotency_key?:string,idempotency_scope?:string,from?:string,meta?:array<string,mixed>} $opts
     */
    public function send(string $channel, string $to, array $payload, array $opts = []): DriverResult
    {
        $this->assertValidPayload($channel, $payload);
        $this->assertValidRecipient($to);

        $idemKey = $this->normalizeIdempotencyKey($opts);
        if ($idemKey !== null) {
            $existing = $this->repository->findByIdempotencyKey($channel, $idemKey);
            if ($existing !== null) {
                return DriverResult::ok($existing['provider_message_id'] ?? null, ['idempotent_replay' => true]);
            }
        }

        $message = $this->buildMessage($channel, $to, $payload, array_merge($opts, [
            'idempotency_key' => $idemKey,
        ]));

        try {
            $uuid = $this->repository->create($this->rowForCreate($message));
        } catch (\Throwable $e) {
            // Lost an idempotency-key race: the unique (channel, idempotency_key)
            // index rejected the duplicate. Return the winner's row instead of
            // surfacing a DB error (spec: "repeat key returns existing message").
            if ($idemKey !== null) {
                $existing = $this->repository->findByIdempotencyKey($channel, $idemKey);
                if ($existing !== null) {
                    return DriverResult::ok($existing['provider_message_id'] ?? null, ['idempotent_replay' => true]);
                }
            }
            throw $e;
        }

        return $this->dispatchToDriver($uuid, $message);
    }

    public function retry(string $messageUuid, ?array $payload = null): DriverResult
    {
        $row = $this->repository->find($messageUuid);
        if ($row === null) {
            throw new \RuntimeException("Conversa: message '{$messageUuid}' not found.");
        }

        $channel = (string) $row['channel'];
        $to = (string) $row['to'];
        $payload ??= $this->reconstructPayload($row);
        if ($payload === null) {
            throw new \RuntimeException(
                "Conversa: cannot retry '{$messageUuid}' — payload was not stored "
                . '(store_body=false); pass a fresh payload.'
            );
        }

        // Retry re-sends against the SAME row (one row per logical message), bumping
        // retry_count — it does not create a new row.
        $this->repository->update($messageUuid, [
            'retry_count' => ((int) ($row['retry_count'] ?? 0)) + 1,
            'status' => 'queued',
            'error' => null,
        ]);

        return $this->dispatchToDriver($messageUuid, $this->buildMessage($channel, $to, $payload, []));
    }

    /** Resolve the driver, send, and record the outcome onto an existing row. */
    private function dispatchToDriver(string $uuid, OutboundMessage $message): DriverResult
    {
        $channel = $message->channel;
        $to = $message->to;

        if (!$this->drivers->available($channel)) {
            $this->repository->update($uuid, ['status' => 'failed', 'error' => 'driver_unavailable']);
            $this->emitFailed($uuid, $channel, $this->driverName($channel), $to, 'driver_unavailable');
            return DriverResult::failed('driver_unavailable');
        }

        $driver = $this->drivers->driverFor($channel);
        $result = $driver->send($message);

        if ($result->ok) {
            $this->repository->update($uuid, [
                'status' => 'sent',
                'provider_message_id' => $result->providerMessageId,
                'provider_response' => $this->encodeResponse($result->rawResponse),
                'sent_at' => date('Y-m-d H:i:s'),
            ]);
            ($this->dispatch)(new MessageSent($uuid, $channel, $driver->getName(), $to, $result->providerMessageId));
        } else {
            $this->repository->update($uuid, [
                'status' => 'failed',
                'error' => $result->error,
                'provider_response' => $this->encodeResponse($result->rawResponse),
            ]);
            $this->emitFailed($uuid, $channel, $driver->getName(), $to, (string) $result->error);
        }

        return $result;
    }

    /** @param array{body?:string,template?:array<string,mixed>} $payload */
    private function assertValidPayload(string $channel, array $payload): void
    {
        $hasBody = isset($payload['body']) && $payload['body'] !== '';
        $hasTemplate = isset($payload['template']);

        if ($hasBody === $hasTemplate) {
            throw new \InvalidArgumentException('Provide exactly one of body / template.');
        }
        if ($hasTemplate && $channel !== 'whatsapp') {
            throw new \InvalidArgumentException("Templates are only valid on the 'whatsapp' channel.");
        }
    }

    private function assertValidRecipient(string $to): void
    {
        if (preg_match('/^\+[1-9]\d{7,14}$/', $to) !== 1) {
            throw new \InvalidArgumentException('Recipient must be an E.164 phone number.');
        }
    }

    /** @param array<string,mixed> $opts */
    private function normalizeIdempotencyKey(array $opts): ?string
    {
        $key = $opts['idempotency_key'] ?? null;
        if ($key === null) {
            return null;
        }

        $key = trim((string) $key);
        if ($key === '') {
            return null;
        }

        $scope = trim((string) ($opts['idempotency_scope'] ?? ''));
        if ($scope === '') {
            return $key;
        }

        return 'scoped:' . hash('sha256', $scope . "\0" . $key);
    }

    /**
     * @param array{body?:string,template?:array<string,mixed>} $payload
     * @param array<string,mixed> $opts
     */
    private function buildMessage(string $channel, string $to, array $payload, array $opts): OutboundMessage
    {
        $from = $opts['from'] ?? null;
        $meta = $opts['meta'] ?? [];
        $idem = $opts['idempotency_key'] ?? null;

        if (isset($payload['template'])) {
            /** @var array{name:string,language?:string,variables?:array<int|string,mixed>,provider_ref?:string} $tpl */
            $tpl = $payload['template'];
            return OutboundMessage::template($channel, $to, $tpl, $from, $idem, $meta);
        }

        return OutboundMessage::text($channel, $to, (string) $payload['body'], $from, $idem, $meta);
    }

    /** @return array<string,mixed> */
    private function rowForCreate(OutboundMessage $m): array
    {
        $storeBody = (bool) ($this->features['store_body'] ?? true);

        $row = [
            'channel' => $m->channel,
            'driver' => $this->driverName($m->channel),
            'to' => $m->to,
            'from' => $m->from,
            'status' => 'queued',
            'idempotency_key' => $m->idempotencyKey,
        ];

        if ($storeBody) {
            $row['body'] = $m->body;
            if ($m->template !== null) {
                $row['template_name'] = $m->template['name'];
                $row['template_vars'] = isset($m->template['variables'])
                    ? json_encode($m->template['variables'], JSON_THROW_ON_ERROR)
                    : null;
            }
        } elseif ($m->template !== null) {
            // Template name is low-sensitivity; keep it for audit even when bodies are off.
            $row['template_name'] = $m->template['name'];
        }

        return $row;
    }

    /** @return array{body?:string,template?:array<string,mixed>}|null */
    private function reconstructPayload(array $row): ?array
    {
        if (($row['body'] ?? null) !== null && $row['body'] !== '') {
            return ['body' => (string) $row['body']];
        }
        if (($row['template_name'] ?? null) !== null) {
            $vars = isset($row['template_vars']) && $row['template_vars'] !== null
                ? json_decode((string) $row['template_vars'], true)
                : [];
            return ['template' => ['name' => (string) $row['template_name'], 'variables' => $vars ?? []]];
        }

        return null;
    }

    private function driverName(string $channel): string
    {
        return $this->drivers->driverKeyFor($channel);
    }

    /**
     * Redact PII (recipient numbers, message/template text) before persisting the
     * provider payload when features.redact_provider_response is true (default).
     *
     * @param array<string,mixed> $raw
     */
    private function encodeResponse(array $raw): ?string
    {
        if ($raw === []) {
            return null;
        }
        if ((bool) ($this->features['redact_provider_response'] ?? true)) {
            $raw = $this->redact($raw);
        }
        return json_encode($raw, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function redact(array $data): array
    {
        $sensitive = ['to', 'from', 'body', 'text', 'Body', 'To', 'From', 'ContentVariables', 'template'];
        foreach ($data as $key => $value) {
            if (in_array((string) $key, $sensitive, true)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }
        return $data;
    }

    private function emitFailed(string $uuid, string $channel, string $driver, string $to, string $reason): void
    {
        $this->logger->warning('conversa.send.failed', [
            'message_uuid' => $uuid, 'channel' => $channel, 'driver' => $driver, 'reason' => $reason,
        ]);
        ($this->dispatch)(new MessageFailed($uuid, $channel, $driver, $to, $reason));
    }
}
