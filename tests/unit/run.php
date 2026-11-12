<?php

declare(strict_types=1);

/**
 * Mini-exécuteur de tests unitaires, sans dépendance ni GLPI (pas de composer).
 *
 *   php tests/unit/run.php [filtre]
 *
 * Chaque fichier tests/unit/*Test.php appelle test('nom', fn) ; une assertion qui échoue ou une exception
 * fait échouer le test. Code de sortie 0 si tout passe, 1 sinon (ou si aucun test n'a tourné).
 */

namespace {
    $GLOBALS['__tests'] = [];

    function test(string $name, callable $fn): void
    {
        $GLOBALS['__tests'][] = [$name, $fn];
    }

    final class AssertionFailed extends \RuntimeException
    {
    }

    function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailed(
                ($message !== '' ? $message . ' : ' : '') . 'attendu ' . var_export($expected, true) . ', obtenu ' . var_export($actual, true)
            );
        }
    }

    function assertTrue(mixed $condition, string $message = ''): void
    {
        assertSame(true, $condition === true, $message !== '' ? $message : 'condition fausse');
    }

    function assertThrows(string $class, callable $fn, string $message = ''): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $class) {
                return;
            }
            throw new AssertionFailed(($message !== '' ? $message . ' : ' : '') . "exception $class attendue, obtenu " . $e::class);
        }
        throw new AssertionFailed(($message !== '' ? $message . ' : ' : '') . "exception $class attendue, aucune levée");
    }
}

namespace {
    $root   = dirname(__DIR__, 2);
    $filter = $argv[1] ?? '';

    // Classes pures du plugin (aucune dépendance à GLPI) : chargées par un autoload PSR-4 minimal
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'GlpiPlugin\\Points\\';
        if (str_starts_with($class, $prefix)) {
            $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });

    foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
        require $file;
    }

    $failed = 0;
    $ran    = 0;
    foreach ($GLOBALS['__tests'] as [$name, $fn]) {
        if ($filter !== '' && !str_contains($name, $filter)) {
            continue;
        }
        $ran++;
        try {
            $fn();
            echo "OK  $name\n";
        } catch (\Throwable $e) {
            $failed++;
            echo "KO  $name : " . $e->getMessage() . "\n";
        }
    }

    echo "\n$ran test(s), " . ($ran - $failed) . " réussi(s), $failed échoué(s)\n";
    exit($ran === 0 || $failed > 0 ? 1 : 0);
}
