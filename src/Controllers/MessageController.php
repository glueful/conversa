<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Controllers;

use Glueful\Auth\UserIdentity;
use Glueful\Extensions\Conversa\ConversaService;
use Glueful\Extensions\Conversa\Repositories\MessageRepository;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Routing\Attributes\QueryParam;
use Symfony\Component\HttpFoundation\Request;

final class MessageController
{
    public function __construct(
        private readonly ConversaService $conversa,
        private readonly MessageRepository $repository,
    ) {
    }

    /**
     * Send an SMS or WhatsApp message.
     */
    #[ApiOperation(
        summary: 'Send Message',
        description: 'Sends an SMS or WhatsApp message through the configured provider driver. '
            . 'Provide exactly one of `body` (free text) or `template` (WhatsApp only). Supply an '
            . '`Idempotency-Key` header (or `idempotency_key` field) to make repeat sends safe; '
            . 'HTTP idempotency keys are scoped to the authenticated user. '
            . 'Body: `channel` (required; sms|whatsapp), `to` (required; E.164 recipient, e.g. '
            . '+15551234567), `body` (message text, use this OR template), `template` (WhatsApp '
            . 'template object {name, language, variables}, use this OR body), `idempotency_key` '
            . '(optional, alternative to the Idempotency-Key header). '
            . 'Requires the `conversa.messages.send` permission.',
        tags: ['Conversa'],
    )]
    #[ApiResponse(200, description: 'Message accepted (or send failed; see `ok`/`error` in data)')]
    #[ApiResponse(422, description: 'Validation failed (missing channel/to, invalid E.164 recipient, '
        . 'or invalid body/template combination)')]
    #[ApiResponse(403, description: 'Missing conversa.messages.send permission')]
    public function store(Request $request): Response
    {
        /** @var array<string,mixed> $in */
        $in = json_decode((string) $request->getContent(), true) ?? [];

        $channel = (string) ($in['channel'] ?? '');
        $to = (string) ($in['to'] ?? '');
        if ($channel === '' || $to === '') {
            return Response::validation(['channel' => 'required', 'to' => 'required']);
        }
        if (!$this->isE164($to)) {
            return Response::validation(['to' => 'Recipient must be an E.164 phone number.']);
        }

        $payload = isset($in['template']) ? ['template' => $in['template']] : ['body' => (string) ($in['body'] ?? '')];
        $opts = [];
        $idem = $request->headers->get('Idempotency-Key') ?? ($in['idempotency_key'] ?? null);
        if ($idem !== null) {
            $opts['idempotency_key'] = (string) $idem;
        }
        $user = $request->attributes->get('auth.user');
        if ($user instanceof UserIdentity) {
            $opts['idempotency_scope'] = $user->id();
        }

        try {
            $result = $this->conversa->send($channel, $to, $payload, $opts);
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['payload' => $e->getMessage()]);
        }

        return Response::success([
            'ok' => $result->ok,
            'provider_message_id' => $result->providerMessageId,
            'error' => $result->error,
        ], $result->ok ? 'Message accepted' : 'Send failed');
    }

    /**
     * List logged messages.
     */
    #[ApiOperation(
        summary: 'List Messages',
        description: 'Lists logged messages (most recent first), optionally filtered by '
            . 'status, channel, or recipient. Requires `conversa.messages.read` because the log '
            . 'can contain recipients and message bodies when body storage is enabled.',
        tags: ['Conversa'],
    )]
    #[QueryParam('status', description: 'Filter by message status')]
    #[QueryParam('channel', description: 'Filter by channel (sms|whatsapp)')]
    #[QueryParam('to', description: 'Filter by recipient phone number')]
    #[QueryParam('page', 'integer', description: 'Page number for pagination (default: 1)')]
    #[QueryParam('per_page', 'integer', description: 'Number of items per page (default: 25, max: 100)')]
    #[ApiResponse(200, description: 'Messages retrieved')]
    #[ApiResponse(403, description: 'Missing conversa.messages.read permission')]
    public function index(Request $request): Response
    {
        $conditions = [];
        foreach (['status', 'channel', 'to'] as $field) {
            $val = $request->query->get($field);
            if ($val !== null && $val !== '') {
                $conditions[$field] = $val;
            }
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('per_page', 25)));

        $result = $this->repository->paginate($page, $perPage, $conditions, ['created_at' => 'DESC']);

        return Response::paginated(
            array_values($result['data']),
            (int) $result['total'],
            (int) $result['current_page'],
            (int) $result['per_page'],
        );
    }

    private function isE164(string $to): bool
    {
        return preg_match('/^\+[1-9]\d{7,14}$/', $to) === 1;
    }
}
