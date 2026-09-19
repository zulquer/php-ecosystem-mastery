<?php
/**
 * ==============================================================================
 * LAB 03: FRANKENPHP WORKER MODE & PHP 8.1+ FIBERS CONCURRENCY
 * ==============================================================================
 * Demuestra a nivel Senior:
 * 1. PHP Fibers (PHP 8.1+): Corrutinas cooperativas con pausa y reanudación explícita.
 * 2. Arquitectura de Alto Rendimiento: PHP-FPM vs FrankenPHP Worker Mode.
 * 3. El Desafío Senior de los Workers: Fugas de memoria y reseteo de estado global.
 * ==============================================================================
 */

echo str_repeat("=", 80) . PHP_EOL;
echo "🧵 [1/2] PHP 8.1+ FIBERS: CORRUTINAS COOPERATIVAS EN ESPACIO DE USUARIO" . PHP_EOL;
echo str_repeat("=", 80) . PHP_EOL;

// Demostración de Fiber nativo de PHP
$fiber = new Fiber(function (): void {
    echo "   [Fiber] 1. Tarea asíncrona iniciada dentro de la Fiber." . PHP_EOL;
    $input = Fiber::suspend("esperando_io_externo");
    echo "   [Fiber] 3. Reanudada con el dato inyectado: '{$input}'" . PHP_EOL;
});

echo "Programa Principal: Iniciando Fiber..." . PHP_EOL;
$status = $fiber->start();
echo "Programa Principal: La Fiber se suspendió con estado: '{$status}'" . PHP_EOL;
echo "Programa Principal: Realizando otros trabajos mientras la I/O finaliza..." . PHP_EOL;
echo "Programa Principal: Reanudando Fiber con el resultado del socket..." . PHP_EOL;
$fiber->resume("payload_recibido_del_servidor");
echo "Programa Principal: Fiber completada con éxito." . PHP_EOL;

echo PHP_EOL . str_repeat("=", 80) . PHP_EOL;
echo "⚡ [2/2] TRADITIONAL PHP-FPM VS FRANKENPHP WORKER MODE (LONG-RUNNING PROCESS)" . PHP_EOL;
echo str_repeat("=", 80) . PHP_EOL;

class Application {
    public static int $bootCount = 0;
    public array $requestScopedState = [];

    public function boot(): void {
        self::$bootCount++;
        // Simula la carga de 2,000 clases, configuración, rutas y service providers de Laravel
        usleep(5000); // 5ms de overhead de arranque
    }

    public function handle(int $requestId): string {
        $this->requestScopedState[] = "Request-Data-{$requestId}";
        return "HTTP 200 OK | Request {$requestId} procesada";
    }

    public function resetState(): void {
        // Crucial en Worker Mode para evitar fugas de memoria entre peticiones
        $this->requestScopedState = [];
    }
}

// 1. Simulación PHP-FPM Tradicional (Share-Nothing: Arrancar y Morir en cada request)
$t0 = microtime(true);
Application::$bootCount = 0;
for ($i = 1; $i <= 5; $i++) {
    $fpmApp = new Application();
    $fpmApp->boot(); // Arranca en cada petición
    $fpmApp->handle($i);
    unset($fpmApp); // Muere y libera todo al terminar la respuesta
}
$t_fpm = (microtime(true) - $t0) * 1000;
echo "1. Modelo PHP-FPM Tradicional (5 peticiones):" . PHP_EOL;
echo "   - Tiempo total: " . number_format($t_fpm, 2) . " ms" . PHP_EOL;
echo "   - Número de Boots del Framework: " . Application::$bootCount . " veces  ⚠️ (Overhead continuo)" . PHP_EOL;

// 2. Simulación FrankenPHP / RoadRunner Worker Mode (Boot una sola vez en RAM)
$t0 = microtime(true);
Application::$bootCount = 0;
$workerApp = new Application();
$workerApp->boot(); // Arranca UNA SOLA VEZ antes del bucle de eventos

for ($i = 1; $i <= 5; $i++) {
    $workerApp->handle($i);
    $workerApp->resetState(); // Limpieza higiénica de estado
}
$t_worker = (microtime(true) - $t0) * 1000;
echo PHP_EOL . "2. Modelo FrankenPHP Worker Mode (5 peticiones):" . PHP_EOL;
echo "   - Tiempo total: " . number_format($t_worker, 2) . " ms" . PHP_EOL;
echo "   - Número de Boots del Framework: " . Application::$bootCount . " vez  ✅ (Cero overhead en cada petición)" . PHP_EOL;
$speedup = $t_fpm / max($t_worker, 0.001);
echo "   - Aceleración observada: ~" . number_format($speedup, 1) . "x más rápido" . PHP_EOL;

echo PHP_EOL . str_repeat("=", 80) . PHP_EOL;
echo "🎯 CONCLUSIÓN SENIOR PHP MODERNO:" . PHP_EOL;
echo "- FrankenPHP y RoadRunner eliminan el cuello de botella histórico de PHP-FPM." . PHP_EOL;
echo "- En Worker Mode, el ingeniero debe cuidar las propiedades estáticas para evitar Memory Leaks." . PHP_EOL;
echo "- PHP 8+ con JIT y Fibers compite directamente en throughput con Go y Node.js." . PHP_EOL;
echo str_repeat("=", 80) . PHP_EOL;
