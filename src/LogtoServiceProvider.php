<?php

namespace TIVENTS\LogtoLaravelSdk;

use Illuminate\Auth\RequestGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use TIVENTS\LogtoLaravelSdk\Guards\LogtoGuard;
use TIVENTS\LogtoLaravelSdk\Middleware\AuthenticateWithLogto;
use TIVENTS\LogtoLaravelSdk\Middleware\EnsureEmailIsVerified;
use TIVENTS\LogtoLaravelSdk\Services\LogtoClient;
use TIVENTS\LogtoLaravelSdk\Services\LogtoSdkAdapter;
use TIVENTS\LogtoLaravelSdk\Services\TokenManager;

class LogtoServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        // Merge configuration
        $this->mergeConfigFrom(
            __DIR__ . '/../config/logto.php', 'logto'
        );

        // Register TokenManager
        $this->app->singleton(TokenManager::class, fn($app) => new TokenManager());

        // Register LogtoSdkAdapter
        $this->app->singleton(LogtoSdkAdapter::class, fn($app) => new LogtoSdkAdapter(
            $app->make(TokenManager::class)
        ));

        // Register LogtoClient
        $this->app->singleton(LogtoClient::class, fn($app) => new LogtoClient(
            $app->make(TokenManager::class),
            $app->make(LogtoSdkAdapter::class)
        ));

        // Register AuthController with its dependencies
        $this->app->bind(
            'TIVENTS\LogtoLaravelSdk\Controllers\AuthController',
            fn($app) => new \TIVENTS\LogtoLaravelSdk\Controllers\AuthController(
                $app->make(LogtoClient::class),
                $app->make(LogtoSdkAdapter::class)
            )
        );

        // Register the main Logto service
        $this->app->singleton('logto', fn($app) => $app->make(LogtoClient::class));

        // Register Auth Guard
        $this->registerAuthGuard();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__ . '/../config/logto.php' => config_path('logto.php'),
        ], 'logto-config');

        // Publish migrations
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'logto-migrations');

        // Register the guard in auth config
        $this->registerGuardInAuthConfig();

        // Register routes
        $this->registerRoutes();

        // Register middlewares
        $this->registerMiddlewares();

        // Register Blade directives
        $this->registerBladeDirectives();
    }

    /**
     * Register the guard in Laravel's auth configuration.
     */
    protected function registerGuardInAuthConfig(): void
    {
        $guardName = config('logto.guard.name', 'logto');
        
        // Only add the guard if it doesn't already exist in the config
        $guards = config('auth.guards', []);
        
        if (!isset($guards[$guardName])) {
            config(['auth.guards.' . $guardName => [
                'driver' => 'session',
                'provider' => config('logto.guard.provider', 'users'),
            ]]);
        }
    }

    /**
     * Register the Logto authentication guard.
     */
    protected function registerAuthGuard(): void
    {
        $guardName = config('logto.guard.name', 'logto');
        
        Auth::extend($guardName, fn($app, $name, array $config) => new LogtoGuard(
            $app->make(LogtoClient::class),
            $app->make(TokenManager::class),
            $app->make('request'),
            Auth::guard($config['provider'] ?? 'users')
        ));
    }

    /**
     * Register package routes.
     */
    protected function registerRoutes(): void
    {
        $router = $this->app->make(Router::class);
        
        // Only register routes if they haven't been registered already
        if (!$router->has('logto.callback')) {
            $router->group([
                'namespace' => 'TIVENTS\\LogtoLaravelSdk\\Controllers',
                'prefix' => 'auth/logto',
                'middleware' => ['web'],
            ], function (Router $router): void {
                // Authorization callback
                $router->get('/callback', [
                    'uses' => 'AuthController@callback',
                    'as' => 'logto.callback',
                ]);

                // Logout
                $router->get('/logout', [
                    'uses' => 'AuthController@logout',
                    'as' => 'logto.logout',
                    'middleware' => ['auth:' . config('logto.guard.name', 'logto')],
                ]);

                // Redirect to Logto for login
                $router->get('/login', [
                    'uses' => 'AuthController@redirectToLogto',
                    'as' => 'logto.login',
                    'middleware' => ['guest:' . config('logto.guard.name', 'logto')],
                ]);

                // API endpoints (for authenticated users)
                $router->group([
                    'prefix' => 'api',
                    'middleware' => ['auth:' . config('logto.guard.name', 'logto')],
                ], function (Router $router): void {
                    $router->get('/user', [
                        'uses' => 'AuthController@userInfo',
                        'as' => 'logto.api.user',
                    ]);

                    $router->post('/refresh', [
                        'uses' => 'AuthController@refreshToken',
                        'as' => 'logto.api.refresh',
                    ]);
                });
            });
        }
    }

    /**
     * Register middlewares.
     */
    protected function registerMiddlewares(): void
    {
        $router = $this->app->make(Router::class);
        
        // Register auth middleware
        $router->aliasMiddleware('auth.logto', AuthenticateWithLogto::class);
        
        // Register email verification middleware
        $router->aliasMiddleware('verified.logto', EnsureEmailIsVerified::class);
    }

    /**
     * Register Blade directives.
     */
    protected function registerBladeDirectives(): void
    {
        // @logto directive for easy authentication links
        Blade::directive('logto', function () {
            $guard = config('logto.guard.name', 'logto');
            return "<?php echo \TIVENTS\LogtoLaravelSdk\Facades\Logto::getAuthorizationUrl(); ?>";
        });

        // @logtoUser directive to get current user info
        Blade::directive('logtoUser', fn() => "<?php echo \Illuminate\Support\Facades\Auth::guard(config('logto.guard.name', 'logto'))->user(); ?>");

        // @logtoLogout directive for logout URL
        Blade::directive('logtoLogout', fn() => "<?php echo route('logto.logout'); ?>");
    }

    /**
     * Get the services provided by the provider.
     */
    #[\Override]
    public function provides(): array
    {
        return [
            'logto',
            LogtoClient::class,
            LogtoSdkAdapter::class,
            TokenManager::class,
            LogtoGuard::class,
        ];
    }
}
