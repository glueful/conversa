<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Extensions\Conversa\Channels\SmsChannel;
use Glueful\Extensions\Conversa\Channels\WhatsAppChannel;
use Glueful\Extensions\Conversa\Controllers\WebhookController;
use Glueful\Extensions\Conversa\Drivers\DriverManager;
use Glueful\Extensions\Conversa\Drivers\LogDriver;
use Glueful\Extensions\Conversa\Drivers\TwilioDriver;
use Glueful\Extensions\Conversa\Drivers\WhatsAppCloudDriver;
use Glueful\Extensions\Conversa\Repositories\MessageRepository;
use Glueful\Extensions\Conversa\Webhooks\TwilioStatusMapper;
use Glueful\Extensions\Conversa\Webhooks\WhatsAppCloudStatusMapper;
use Glueful\Extensions\ServiceProvider;
use Glueful\Http\Client;
use Glueful\Notifications\Services\ChannelManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ConversaServiceProvider extends ServiceProvider
{
    public function getName(): string
    {
        return 'Conversa';
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function services(): array
    {
        return [
            // Need config + HTTP client => array static factory (prod-safe; receives $c).
            DriverManager::class => ['shared' => true, 'factory' => [self::class, 'makeDriverManager']],
            ConversaService::class => ['shared' => true, 'factory' => [self::class, 'makeConversaService']],
            SmsChannel::class => ['shared' => true, 'factory' => [self::class, 'makeSmsChannel']],
            WhatsAppChannel::class => ['shared' => true, 'factory' => [self::class, 'makeWhatsAppChannel']],
            WebhookController::class => ['shared' => true, 'factory' => [self::class, 'makeWebhookController']],

            // Deps are all services => autowire.
            MessageRepository::class => ['class' => MessageRepository::class, 'shared' => true, 'autowire' => true],
            Controllers\MessageController::class => [
                'class' => Controllers\MessageController::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    public static function makeDriverManager(ContainerInterface $c): DriverManager
    {
        $cfg = self::conversaConfig($c);
        $http = $c->get(Client::class);
        $logger = self::logger($c);
        $templates = $cfg['templates'] ?? [];

        $drivers = [
            'log' => new LogDriver($logger),
            'twilio' => new TwilioDriver($http, $cfg['drivers']['twilio'] ?? [], $templates),
            'whatsapp_cloud' => new WhatsAppCloudDriver($http, $cfg['drivers']['whatsapp_cloud'] ?? [], $templates),
        ];

        return new DriverManager($cfg['default'] ?? [], $drivers);
    }

    public static function makeConversaService(ContainerInterface $c): ConversaService
    {
        $cfg = self::conversaConfig($c);
        $events = $c->get(EventService::class);

        return new ConversaService(
            $c->get(DriverManager::class),
            $c->get(MessageRepository::class),
            $cfg['features'] ?? [],
            static fn(object $e) => $events->dispatch($e),
            self::logger($c),
        );
    }

    public static function makeSmsChannel(ContainerInterface $c): SmsChannel
    {
        return new SmsChannel($c->get(ConversaService::class), $c->get(DriverManager::class)->available('sms'));
    }

    public static function makeWhatsAppChannel(ContainerInterface $c): WhatsAppChannel
    {
        return new WhatsAppChannel(
            $c->get(ConversaService::class),
            $c->get(DriverManager::class)->available('whatsapp'),
        );
    }

    public static function makeWebhookController(ContainerInterface $c): WebhookController
    {
        $cfg = self::conversaConfig($c);
        $events = $c->get(EventService::class);

        return new WebhookController(
            ['twilio' => new TwilioStatusMapper(), 'whatsapp_cloud' => new WhatsAppCloudStatusMapper()],
            $cfg['drivers'] ?? [],
            $c->get(MessageRepository::class),
            static fn(object $e) => $events->dispatch($e),
            $cfg['webhook_base_url'] ?? null,
        );
    }

    /** @return array<string,mixed> */
    private static function conversaConfig(ContainerInterface $c): array
    {
        return (array) config($c->get(ApplicationContext::class), 'conversa', []);
    }

    private static function logger(ContainerInterface $c): LoggerInterface
    {
        return $c->has(LoggerInterface::class) ? $c->get(LoggerInterface::class) : new NullLogger();
    }

    public function register(ApplicationContext $context): void
    {
        $this->mergeConfig('conversa', require __DIR__ . '/../config/conversa.php');
    }

    public function boot(ApplicationContext $context): void
    {
        if ($this->app->has(ChannelManager::class)) {
            $cm = $this->app->get(ChannelManager::class);
            $cm->registerChannel($this->app->get(SmsChannel::class));
            $cm->registerChannel($this->app->get(WhatsAppChannel::class));
        }

        $this->loadMigrationsFrom(__DIR__ . '/../migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
    }
}
