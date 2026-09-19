<?php
/**
 * ==============================================================================
 * LAB 01: ZEND ENGINE INTERNALS, COPY-ON-WRITE (COW) & GARBAGE COLLECTION
 * ==============================================================================
 * Demuestra a nivel Senior:
 * 1. Arquitectura de Zend Engine 4 (Zend VM, Zvals y Opcodes).
 * 2. Mecanismo Copy-on-Write (COW): Cómo PHP optimiza memoria retrasando copias.
 * 3. Ciclos de referencia en Zvals y el Recolector de Basura (Zend GC).
 * ==============================================================================
 */

echo str_repeat("=", 80) . PHP_EOL;
echo "🧠 [1/3] MECANISMO COPY-ON-WRITE (COW) EN ZEND ENGINE" . PHP_EOL;
echo str_repeat("=", 80) . PHP_EOL;

function format_bytes(int $bytes): string {
    return number_format($bytes / 1024, 2) . " KB";
}

$mem_initial = memory_get_usage();
echo "1. Memoria base antes de crear array: " . format_bytes($mem_initial) . PHP_EOL;

// Crear un array grande de 100,000 elementos
$original = range(1, 100_000);
$mem_after_create = memory_get_usage();
$array_cost = $mem_after_create - $mem_initial;
echo "2. Memoria tras crear \$original (100k ints): +" . format_bytes($array_cost) . PHP_EOL;

// Asignar a otra variable (¡Zend Engine NO copia el buffer gracias a COW!)
$copy = $original;
$mem_after_assign = memory_get_usage();
$overhead = $mem_after_assign - $mem_after_create;
echo "3. Memoria tras \$copy = \$original: +" . format_bytes($overhead) . "  ✅ (¡COW: 0 bytes duplicados!)" . PHP_EOL;

// Ahora forzamos la mutación -> Zend Engine detecta refcount > 1 y realiza la copia real
$copy[0] = 999_999;
$mem_after_mutate = memory_get_usage();
$copy_cost = $mem_after_mutate - $mem_after_assign;
echo "4. Memoria tras mutar \$copy[0] (Split Zval): +" . format_bytes($copy_cost) . "  ⚠️ (Duplicación real ejecutada)" . PHP_EOL;

unset($original, $copy);

echo PHP_EOL . str_repeat("=", 80) . PHP_EOL;
echo "♻️ [2/3] ZVAL REFERENCE COUNTING & CICLOS RECOLECTADOS POR ZEND GC" . PHP_EOL;
echo str_repeat("=", 80) . PHP_EOL;

class ContainerNode {
    public string $name;
    public ?ContainerNode $child = null;

    public function __construct(string $name) {
        $this->name = $name;
    }
}

$nodeA = new ContainerNode("Node-A");
$nodeB = new ContainerNode("Node-B");

// Crear dependencia circular (A -> B -> A)
$nodeA->child = $nodeB;
$nodeB->child = $nodeA;

echo "Ciclo de referencias creado entre Node-A y Node-B." . PHP_EOL;

// Eliminar referencias externas
unset($nodeA, $nodeB);

// En este punto, los nodos siguen en memoria porque sus refcounts son > 0 debido al ciclo.
// El algoritmo de recolección de ciclos de Zend Engine (Root Buffer de 10,000 slots) los limpia.
$collected = gc_collect_cycles();
echo "Zend Garbage Collector forzado -> Ciclos inalcanzables recolectados: " . $collected . " zvals" . PHP_EOL;

echo PHP_EOL . str_repeat("=", 80) . PHP_EOL;
echo "⚡ [3/3] PIPELINE DE COMPILACIÓN ZEND ENGINE (PHP 8+)" . PHP_EOL;
echo str_repeat("=", 80) . PHP_EOL;
echo "1. Lexer (Re2c)       -> Tokeniza el código fuente en Tokens (T_VARIABLE, T_STRING)." . PHP_EOL;
echo "2. Parser (Bison)     -> Genera el Árbol de Sintaxis Abstracta (AST)." . PHP_EOL;
echo "3. Compiler           -> Traduce el AST a Zend Opcodes (Instrucciones de VM)." . PHP_EOL;
echo "4. OPcache            -> Almacena Opcodes en memoria compartida (SHM) saltando pasos 1-3." . PHP_EOL;
echo "5. JIT (PHP 8+)       -> Detecta 'Hot Opcodes' y los compila a código máquina x86_64/ARM." . PHP_EOL;
echo "6. Zend VM Executor   -> Ejecuta el bucle de dispatch de opcodes (ZEND_VM_HANDLER)." . PHP_EOL;
echo str_repeat("=", 80) . PHP_EOL;
