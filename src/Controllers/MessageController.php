<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Controllers;

use Glueful\Extensions\Conversa\ConversaService;
use Glueful\Extensions\Conversa\Repositories\MessageRepository;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class MessageController
{
    public function __construct(
        private readonly ConversaService $conversa,
        private readonly MessageRepository $repository,
    ) {
    }

    public function store(Request $request): Response
    {
        /** @var array<string,mixed> $in */
        $in = json_decode((string) $request->getContent(), true) ?? [];

        $channel = (string) ($in['channel'] ?? '');
        $to = (string) ($in['to'] ?? '');
        if ($channel === '' || $to === '') {
            return Response::validation(['channel' => 'required', 'to' => 'required']);
        }

        $payload = isset($in['template']) ? ['template' => $in['template']] : ['body' => (string) ($in['body'] ?? '')];
        $opts = [];
        $idem = $request->headers->get('Idempotency-Key') ?? ($in['idempotency_key'] ?? null);
        if ($idem !== null) {
            $opts['idempotency_key'] = (string) $idem;
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
}
