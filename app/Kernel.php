<?php
declare(strict_types=1);

namespace App;

use App\Http\Controllers\StaffTreasuryController as Staff;
use App\Accounting\Books;
use App\Http\Controllers\OfficeController;
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

    // Production does not check the compiled container for staleness; scripts/upgrade-office.sh deletes this folder after each pull.
    public function getCacheDir(): string
    {
        return ($_SERVER['LARAVEL_STORAGE_PATH'] ?? $this->getProjectDir().'/storage')
            .'/framework/cache/symfony/short-urls-v1/'.$this->environment;
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
        // Recompile a template when its file changes, so a git pull shows new screens without clearing the cache.
        $container->extension('twig', ['default_path' => '%kernel.project_dir%/templates', 'auto_reload' => true]);
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
                ['path' => '^/(login|up|forgot-password|reset-password/[0-9a-f]{64})$', 'roles' => 'PUBLIC_ACCESS'],
                ['path' => '^/', 'roles' => 'ROLE_STAFF'],
            ],
        ]);
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(\PDO::class, \PDO::class)->factory([Database::class, 'open']);
        $services->set(Language::class);
        $services->set(FormExpired::class);
        $services->set(SecurityHeaders::class);
        $services->set(StaffProvider::class);
        $services->set(OfficeController::class)->public();
        $services->set(Staff::class)->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        // Short single-branch paths; ?branch=N only appears for a branch other than the user's main one.
        $routes->add('overview','/overview')->controller([OfficeController::class,'overview'])->methods(['GET']);
        $routes->add('control','/settings')->controller([OfficeController::class,'control'])->methods(['GET','POST']);
        $routes->add('office','/{section}/{doc}')->controller([OfficeController::class,'office'])->defaults(['branch'=>0,'doc'=>''])
            ->requirements(['section'=>'dashboard|new|edit|d|ledger|statement|reports|export|workbook','doc'=>'\d*'])->methods(['GET','POST']);
        $routes->add('task','/{task}')->controller([OfficeController::class,'office'])->defaults(['branch'=>0,'section'=>'new'])
            ->requirements(['task'=>implode('|',array_map(static fn($k)=>str_replace('_','-',$k),array_keys(Books::TYPES)))])->methods(['GET','POST']);
        // Old long addresses keep working for bookmarks.
        $routes->add('control_old','/control')->controller([OfficeController::class,'legacy'])->methods(['GET']);
        $routes->add('office_old','/branches/{branch}/office/{section}')->controller([OfficeController::class,'legacy'])
            ->requirements(['branch'=>'\d+','section'=>'dashboard|post|report|export|workbook|statements'])->methods(['GET','POST']);
        $routes->add('login', '/login')->controller([Staff::class, 'login'])->methods(['GET', 'POST']);
        $routes->add('forgot_password', '/forgot-password')->controller([Staff::class, 'forgotPassword'])->methods(['GET', 'POST']);
        $routes->add('reset_password', '/reset-password/{token}')->controller([Staff::class, 'resetPassword'])
            ->requirements(['token' => '[0-9a-f]{64}'])->methods(['GET', 'POST']);
        $routes->add('logout', '/logout')->controller([Staff::class, 'logout'])->methods(['POST']);
        $routes->add('ping', '/ping')->controller([Staff::class, 'ping'])->methods(['GET']);
        $routes->add('health', '/up')->controller([Staff::class, 'health'])->methods(['GET']);
        $routes->add('home', '/')->controller([Staff::class, 'home'])->methods(['GET']);
        $routes->add('branch', '/branches/{branch}')->controller([Staff::class, 'branch'])
            ->requirements(['branch' => '\d+'])->methods(['GET']);
        $routes->add('transfer', '/branches/{branch}/transfers')->controller([Staff::class, 'transfer'])
            ->requirements(['branch' => '\d+'])->methods(['POST']);
    }
}
