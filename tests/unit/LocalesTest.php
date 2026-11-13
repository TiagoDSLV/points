<?php

declare(strict_types=1);

/**
 * @return array<string, string> msgid => msgstr d'un .po à chaînes sur une ligne
 */
function poEntries(string $file): array
{
    $entries = [];
    $id = null;
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^msgid "(.*)"$/', $line, $m) === 1) {
            $id = stripcslashes($m[1]);
        } elseif ($id !== null && preg_match('/^msgstr "(.*)"$/', $line, $m) === 1) {
            $entries[$id] = stripcslashes($m[1]);
            $id = null;
        }
    }

    return $entries;
}

/**
 * Chaînes `__('…', 'points')` du plugin (PHP et Twig).
 *
 * @return list<string>
 */
function sourceMsgids(): array
{
    $root = dirname(__DIR__, 2);
    $files = array_merge(
        glob($root . '/src/*.php') ?: [],
        glob($root . '/src/Domain/*.php') ?: [],
        glob($root . '/src/Console/*.php') ?: [],
        glob($root . '/front/*.php') ?: [],
        glob($root . '/templates/*.twig') ?: [],
    );
    $ids = [];
    foreach ($files as $file) {
        preg_match_all('/\b__s?\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*,\s*[\'"]points[\'"]\s*\)/', file_get_contents($file), $m);
        foreach ($m[2] as $id) {
            $ids[] = stripcslashes($id);
        }
    }

    return array_values(array_unique($ids));
}

test('Traductions : chaque chaîne du code est traduite en fr_FR et présente en en_GB', function (): void {
    $fr = poEntries(__DIR__ . '/../../locales/fr_FR.po');
    $en = poEntries(__DIR__ . '/../../locales/en_GB.po');
    $ids = sourceMsgids();
    assertTrue(count($ids) >= 20, 'chaînes trouvées dans le code : ' . count($ids));
    foreach ($ids as $id) {
        assertTrue(isset($fr[$id]) && $fr[$id] !== '', "fr_FR : « $id » non traduit");
        assertTrue(isset($en[$id]) && $en[$id] !== '', "en_GB : « $id » absent");
    }
    assertSame(true, $fr['Validate'] !== 'Validate', 'fr_FR traduit réellement');
});

test('Traductions : pas de chaîne orpheline dans les .po', function (): void {
    $ids = sourceMsgids();
    foreach (['fr_FR', 'en_GB'] as $lang) {
        foreach (array_keys(poEntries(__DIR__ . "/../../locales/$lang.po")) as $id) {
            assertTrue($id === '' || in_array($id, $ids, true), "$lang : « $id » n'est plus utilisé");
        }
    }
});
