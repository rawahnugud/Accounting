<?php
declare(strict_types=1);

namespace App;

use App\Http\Controllers\StaffTreasuryController as Staff;
use App\Security\StaffProvider;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
    }

    public function getCacheDir(): string
    {
        return ($_SERVER['LARAVEL_STORAGE_PATH'] ?? $this->getProjectDir().'/storage')
            .'/framework/cache/symfony/'.$this->environment;
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => '%env(APP_KEY)%',
            'http_method_override' => false,
            'router' => ['utf8' => true],
            'csrf_protection' => true,
            'session' => [
                'name' => 'mt_accounting_session',
                'handler_id' => 'session.handler.native_file',
                'save_path' => ($_SERVER['LARAVEL_STORAGE_PATH'] ?? $this->getProjectDir().'/storage').'/framework/sessions',
                'cookie_secure' => 'auto',
                'cookie_samesite' => 'lax',
                'cookie_httponly' => true,
            ],
        ]);
        $container->extension('twig', ['default_path' => '%kernel.project_dir%/templates']);
        $container->extension('security', [
            'password_hashers' => [\Symfony\Component\Security\Core\User\InMemoryUser::class => 'auto'],
            'providers' => ['staff' => ['id' => StaffProvider::class]],
            'firewalls' => ['main' => [
                'lazy' => true, 'provider' => 'staff',
                'form_login' => ['login_path' => 'login', 'check_path' => 'login',
                    'username_parameter' => 'email', 'password_parameter' => 'password',
                    'enable_csrf' => true, 'csrf_parameter' => '_token', 'csrf_token_id' => 'staff',
                    'default_target_path' => '/'],
                'login_throttling' => ['max_attempts' => 6, 'interval' => '1 minute'],
                'logout' => ['path' => 'logout', 'target' => '/login',
                    'enable_csrf' => true, 'csrf_parameter' => '_token', 'csrf_token_id' => 'staff'],
            ]],
            'access_control' => [
                ['path' => '^/(login|up)$', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/', 'roles' => 'ROLE_STAFF'],
            ],
        ]);
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(\PDO::class, \PDO::class)->factory([Database::class, 'open']);
        $services->set(StaffProvider::class);
        $services->set(Staff::class)->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('login', '/login')->controller([Staff::class, 'login'])->methods(['GET', 'POST']);
        $routes->add('logout', '/logout')->controller([Staff::class, 'logout'])->methods(['POST']);
        $routes->add('health', '/up')->controller([Staff::class, 'health'])->methods(['GET']);
        $routes->add('home', '/')->controller([Staff::class, 'home'])->methods(['GET']);
        $routes->add('branch', '/branches/{branch}')->controller([Staff::class, 'branch'])
            ->requirements(['branch' => '\d+'])->methods(['GET']);
        $routes->add('transfer', '/branches/{branch}/transfers')->controller([Staff::class, 'transfer'])
            ->requirements(['branch' => '\d+'])->methods(['POST']);
    }
}
