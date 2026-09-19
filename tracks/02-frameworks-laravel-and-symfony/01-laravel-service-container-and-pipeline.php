<?php
/**
 * ==============================================================================
 * LAB 02: LARAVEL SERVICE CONTAINER (IOC) & MIDDLEWARE PIPELINE INTERNALS
 * ==============================================================================
 * Demuestra a nivel Senior:
 * 1. Inversión de Control (IoC) y Autowiring basado en PHP Reflection API.
 * 2. Enlace de Interfaces a Implementaciones Concretas y Singletons.
 * 3. Patrón Pipeline / Cebolla (Middleware Onion) de Laravel mediante array_reduce.
 * ==============================================================================
 */

echo str_repeat("=", 80) . PHP_EOL;
echo "💉 [1/2] MOTOR DE INVERSIÓN DE CONTROL (LARAVEL SERVICE CONTAINER)" . PHP_EOL;
echo str_repeat("=", 80) . PHP_EOL;

interface PaymentGatewayInterface {
    public function charge(float $amount): string;
}

class StripePaymentGateway implements PaymentGatewayInterface {
    public function charge(float $amount): string {
        return "Cobro de \${$amount} procesado exitosamente vía Stripe API.";
    }
}

class OrderService {
    public function __construct(
        protected PaymentGatewayInterface $gateway
    ) {}

    public function processOrder(int $orderId, float $amount): string {
        $result = $this->gateway->charge($amount);
        return "Orden #{$orderId} completada -> " . $result;
    }
}

// Implementación minimalista del Container de Laravel
class Container {
    protected array $bindings = [];
    protected array $instances = [];

    public function bind(string $abstract, string|Closure $concrete): void {
        $this->bindings[$abstract] = $concrete;
    }

    public function singleton(string $abstract, string|Closure $concrete): void {
        $this->bind($abstract, $concrete);
        $this->instances[$abstract] = null;
    }

    public function make(string $abstract): object {
        // Retornar singleton si ya fue instanciado
        if (array_key_exists($abstract, $this->instances) && $this->instances[$abstract] !== null) {
            return $this->instances[$abstract];
        }

        $concrete = $this->bindings[$abstract] ?? $abstract;

        if ($concrete instanceof Closure) {
            $object = $concrete($this);
        } else {
            $object = $this->build($concrete);
        }

        if (array_key_exists($abstract, $this->instances)) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    protected function build(string $concrete): object {
        $reflector = new ReflectionClass($concrete);

        if (!$reflector->isInstantiable()) {
            throw new Exception("La clase {$concrete} no es instanciable.");
        }

        $constructor = $reflector->getConstructor();
        if ($constructor === null) {
            return new $concrete;
        }

        $dependencies = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            if ($type && !$type->isBuiltin()) {
                $dependencies[] = $this->make($type->getName());
            } else {
                throw new Exception("No se puede autowirear parámetro escalar: {$param->getName()}");
            }
        }

        return $reflector->newInstanceArgs($dependencies);
    }
}

$app = new Container();

// Vincular interfaz a clase concreta (Dependency Inversion Principle)
$app->bind(PaymentGatewayInterface::class, StripePaymentGateway::class);

// Resolver OrderService con autowiring recursivo
/** @var OrderService $orderService */
$orderService = $app->make(OrderService::class);
echo "✅ Autowiring completado:" . PHP_EOL;
echo "   " . $orderService->processOrder(8891, 150.00) . PHP_EOL;

echo PHP_EOL . str_repeat("=", 80) . PHP_EOL;
echo "🧅 [2/2] PATRÓN MIDDLEWARE PIPELINE (ONION ARCHITECTURE CON ARRAY_REDUCE)" . PHP_EOL;
echo str_repeat("=", 80) . PHP_EOL;

class Request {
    public function __construct(
        public string $path,
        public array $headers = []
    ) {}
}

class Response {
    public function __construct(
        public int $status,
        public string $body
    ) {}
}

interface Middleware {
    public function handle(Request $request, Closure $next): Response;
}

class AuthMiddleware implements Middleware {
    public function handle(Request $request, Closure $next): Response {
        echo "   [Auth] 🛡️ Verificando token de autenticación..." . PHP_EOL;
        if (!isset($request->headers['Authorization'])) {
            return new Response(401, "Unauthorized");
        }
        return $next($request);
    }
}

class RateLimitMiddleware implements Middleware {
    public function handle(Request $request, Closure $next): Response {
        echo "   [RateLimit] ⏱️ Comprobando límite de peticiones por segundo..." . PHP_EOL;
        $response = $next($request);
        echo "   [RateLimit] 📊 Añadiendo cabeceras X-RateLimit-Remaining." . PHP_EOL;
        return $response;
    }
}

// Simulador de Pipeline de Laravel
class Pipeline {
    protected array $pipes = [];
    protected ?Request $passable = null;

    public function send(Request $passable): static {
        $this->passable = $passable;
        return $this;
    }

    public function through(array $pipes): static {
        $this->pipes = $pipes;
        return $this;
    }

    public function then(Closure $destination): Response {
        $pipeline = array_reduce(
            array_reverse($this->pipes),
            function (Closure $next, Middleware $pipe) {
                return function (Request $request) use ($next, $pipe) {
                    return $pipe->handle($request, $next);
                };
            },
            $destination
        );

        return $pipeline($this->passable);
    }
}

$request = new Request('/api/v1/checkout', ['Authorization' => 'Bearer token-xyz']);
$pipeline = (new Pipeline())
    ->send($request)
    ->through([new AuthMiddleware(), new RateLimitMiddleware()])
    ->then(function (Request $req) {
        echo "   [Controller] 🚀 Ejecutando lógica de negocio del controlador." . PHP_EOL;
        return new Response(200, json_encode(['order' => 'created']));
    });

echo "Resultado del Pipeline: Status {$pipeline->status} -> {$pipeline->body}" . PHP_EOL;
echo str_repeat("=", 80) . PHP_EOL;
