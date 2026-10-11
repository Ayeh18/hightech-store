<?php

namespace App\Providers;

use App\Models\User;
use App\Support\ApiDocs\MoneyToSchema;
use App\Support\ApiDocs\PublishedReference;
use Dedoc\Scramble\CacheableGenerator;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\Tag;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * The API reference (OpenAPI) is written by a tool, Scramble, that reads the routes, the validation rules and the
 * response classes: it cannot drift from the code the way hand-written documentation does. This provider adds what the
 * tool cannot read from the code by itself: how to log in, which group each endpoint belongs to, and which permission and
 * rate limit each one has (taken from the route's own middleware, so those cannot drift either).
 *
 * Read it at /docs/api, or as a file in backend/openapi.json (refresh it with `composer docs`).
 */
class ApiDocsServiceProvider extends ServiceProvider
{
    /**
     * Group names, in the order they are shown, with the controllers in each. A controller is found by the END of its
     * class name, so "Admin\OrderController" must be listed before "OrderController".
     *
     * @var array<string, array{0: string, 1: list<string>}> group => [description, controllers]
     */
    private const GROUPS = [
        'Account' => ['Registering, logging in and out, and the delivery addresses of the logged-in customer.', ['AuthController', 'AddressController']],
        'Catalog' => ['Products, categories, brands and the specifications a category has. Reading is public; changing needs a permission.', ['Catalog\ProductController', 'Catalog\CategoryController', 'Catalog\BrandController', 'Catalog\SpecDefinitionController', 'Catalog\ProductImageController', 'Catalog\InventoryController']],
        'PC builder' => ['Choosing parts slot by slot, checking that they fit together, and saving builds.', ['PcBuilderController', 'BuildController', 'CompatibilityRuleController']],
        'Cart' => ['The logged-in customer\'s cart. Every answer is the whole cart, re-priced and re-checked by the server.', ['CartController', 'CartItemController', 'CartProductController', 'CartBuildController']],
        'Checkout and orders' => ['Turning the cart into an order, and the customer\'s own orders.', ['CheckoutController', 'Orders\OrderController', 'OrderRefundController']],
        'Payments' => ['Starting a payment, and the webhook the payment company calls.', ['Orders\PaymentController', 'PaymentWebhookController']],
        'FakePay (test gateway)' => ['The pretend payment company used in development and demos. Not available in production mode.', ['FakeGatewayController']],
        'Admin: orders and fulfilment' => ['Staff tools for getting orders to customers.', ['Admin\OrderController', 'FulfilmentController']],
        'Admin: payments and refunds' => ['Every payment with its history, refunds, and checking the shop\'s books against the payment company\'s.', ['Admin\PaymentController', 'RefundController', 'ReconciliationController']],
        'Admin: reports' => ['The dashboard, sales reports and the audit log.', ['DashboardController', 'ReportController', 'AuditLogController']],
        'Admin: shop settings' => ['Coupons and the people who may use the admin area.', ['CouponController', 'UserController']],
    ];

    public function register(): void
    {
        // Away from a developer's machine, show the published copy instead of working the reference out on every visit.
        $this->app->bind(CacheableGenerator::class, PublishedReference::class);
    }

    public function boot(): void
    {
        // Who may read the reference. On a developer's machine and a demo server: anyone. On a real shop: nobody.
        Gate::define('viewApiDocs', fn (?User $user = null) => ! $this->app->isProduction());

        Scramble::registerExtension(MoneyToSchema::class);
        Scramble::resolveTagsUsing(fn (RouteInfo $route) => [self::groupOf($route->className())]);

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->info->title = 'HighTech Store API';
                // A relative address: the reference then works wherever it is opened (localhost, a demo server...).
                $openApi->servers = [Server::make('/api')];
                $openApi->tags = collect(self::GROUPS)->map(fn (array $group, string $name) => new Tag($name, $group[0]))->values()->all();
                self::describePageNumberOfPaginationLinks($openApi);
                $openApi->secure(
                    SecurityScheme::apiKey('cookie', (string) config('session.cookie'))
                        ->as('session')
                        ->setDescription('The session cookie set by logging in. See "Logging in" above: requests that change something also need the `X-XSRF-TOKEN` header.'),
                );
            })
            ->withOperationTransformers(function (Operation $operation, RouteInfo $routeInfo): void {
                $middleware = collect($routeInfo->route->gatherMiddleware())->filter(fn ($name) => is_string($name));
                $notes = [];

                if (! $middleware->contains(fn (string $name) => Str::is(['auth', 'auth:*'], $name))) {
                    $operation->security = []; // public: no lock
                }

                // "can:orders.manage" => the permission the route itself enforces. "admin.access" (the door to the whole
                // admin area) is implied by every other admin permission, so it is only named when it stands alone.
                $permissions = $middleware->filter(fn (string $name) => str_starts_with($name, 'can:'))->map(fn (string $name) => substr($name, 4))->values();
                $specific = $permissions->reject(fn (string $permission) => $permission === 'admin.access');
                foreach (($specific->isNotEmpty() ? $specific : $permissions) as $permission) {
                    $notes[] = "**Permission:** `{$permission}`";
                }

                // "throttle:20,1,checkout" => 20 requests per minute, counted for this route alone.
                if ($throttle = $middleware->first(fn (string $name) => str_starts_with($name, 'throttle:'))) {
                    [$attempts, $minutes] = array_pad(explode(',', substr($throttle, 9)), 2, '1');
                    $per = (int) $minutes === 1 ? 'minute' : "{$minutes} minutes";
                    $notes[] = "**Rate limit:** {$attempts} requests per {$per}";
                    $operation->addResponse(self::messageResponse(429, 'Too many requests. The `Retry-After` header says how many seconds to wait.'));
                }

                if ($notes !== []) {
                    $operation->description = trim($operation->description."\n\n".implode(' · ', $notes));
                }
            });
    }

    /**
     * A paged list has "meta.links": the numbered buttons of a pager. Laravel 13 added the page number to each one; the
     * documentation tool does not know that yet, so it is added here (ReferenceMatchesRealAnswersTest notices if this
     * ever stops being true).
     */
    private static function describePageNumberOfPaginationLinks(OpenApi $openApi): void
    {
        foreach ($openApi->paths as $path) {
            foreach ($path->operations as $operation) {
                foreach ($operation->responses ?? [] as $response) {
                    $body = $response instanceof Response ? ($response->content['application/json'] ?? null) : null;
                    $links = $body instanceof Schema && $body->type instanceof ObjectType ? ($body->type->properties['meta'] ?? null) : null;
                    $links = $links instanceof ObjectType ? ($links->properties['links'] ?? null) : null;

                    if ($links instanceof ArrayType && $links->items instanceof ObjectType && $links->items->hasProperty('label')) {
                        $links->items->addProperty('page', (new IntegerType)->nullable(true)->setDescription('The page this button leads to; null for "..." and for a disabled Previous/Next.'));
                    }
                }
            }
        }
    }

    private static function groupOf(?string $controller): string
    {
        foreach (self::GROUPS as $name => [, $controllers]) {
            foreach ($controllers as $suffix) {
                if ($controller !== null && str_ends_with($controller, '\\'.$suffix)) {
                    return $name;
                }
            }
        }

        // A new controller that nobody added to GROUPS yet still shows up, under a name that says so.
        return 'Other';
    }

    private static function messageResponse(int $status, string $description): Response
    {
        $body = (new ObjectType)->addProperty('message', new StringType)->setRequired(['message']);

        return Response::make($status)->setDescription($description)->setContent('application/json', Schema::fromType($body));
    }
}
