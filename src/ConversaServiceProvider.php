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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ConversaServiceProvider extends ServiceProvider
{
    private static ?string $cachedVersion = null;

    /** Read the extension version from composer.json (cached). */
    public static function composerVersion(): string
    {
        if (self::$cachedVersion === null) {
            $path = __DIR__ . '/../composer.json';
            $composer = json_decode((string) file_get_contents($path), true);
            self::$cachedVersion = $composer['extra']['glueful']['version'] ?? '0.0.0';
        }

        return self::$cachedVersion;
    }

    public function getName(): string
    {
        return 'Conversa';
    }

    public function getVersion(): string
    {
        return self::composerVersion();
    }

    public function getDescription(): string
    {
        return 'SMS & WhatsApp messaging channels for Glueful';
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
        // Register the SMS + WhatsApp channels through the framework's extension helper
        // (1.51.0+). It resolves the shared container ChannelManager and no-ops if the
        // notification subsystem isn't present — now the only wiring path (the framework no
        // longer hardcodes notification providers).
        $this->registerNotificationChannel($this->app->get(SmsChannel::class));
        $this->registerNotificationChannel($this->app->get(WhatsAppChannel::class));

        $this->loadMigrationsFrom(__DIR__ . '/../migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');

        // Register extension metadata for CLI and diagnostics.
        try {
            $this->app->get(\Glueful\Extensions\ExtensionManager::class)->registerMeta(self::class, [
                'slug' => 'conversa',
                'name' => 'Conversa',
                'version' => self::composerVersion(),
                'description' => 'SMS & WhatsApp messaging channels for Glueful',
            ]);
        } catch (\Throwable $e) {
            error_log('[Conversa] Failed to register extension metadata: ' . $e->getMessage());
        }
    }
}
