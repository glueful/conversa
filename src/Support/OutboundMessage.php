<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Support;

/**
 * Immutable description of one outbound message. Exactly one of body/template is set.
 */
final class OutboundMessage
{
    /**
     * @param array{name:string,language?:string,variables?:array<int|string,mixed>,provider_ref?:string}|null $template
     * @param array<string,mixed> $meta
     */
    private function __construct(
        public readonly string $channel,
        public readonly string $to,
        public readonly ?string $body,
        public readonly ?array $template,
        public readonly ?string $from = null,
        public readonly ?string $idempotencyKey = null,
        public readonly array $meta = [],
        public readonly ?string $notifiableType = null,
        public readonly ?string $notifiableId = null,
    ) {
    }

    /** @param array<string,mixed> $meta */
    public static function text(
        string $channel,
        string $to,
        string $body,
        ?string $from = null,
        ?string $idempotencyKey = null,
        array $meta = [],
        ?string $notifiableType = null,
        ?string $notifiableId = null,
    ): self {
        return new self($channel, $to, $body, null, $from, $idempotencyKey, $meta, $notifiableType, $notifiableId);
    }

    /**
     * @param array{name:string,language?:string,variables?:array<int|string,mixed>,provider_ref?:string} $template
     * @param array<string,mixed> $meta
     */
    public static function template(
        string $channel,
        string $to,
        array $template,
        ?string $from = null,
        ?string $idempotencyKey = null,
        array $meta = [],
        ?string $notifiableType = null,
        ?string $notifiableId = null,
    ): self {
        return new self($channel, $to, null, $template, $from, $idempotencyKey, $meta, $notifiableType, $notifiableId);
    }

    public function isText(): bool
    {
        return $this->body !== null;
    }

    public function isTemplate(): bool
    {
        return $this->template !== null;
    }
}
