<?php

declare(strict_types=1);

use GlpiPlugin\Points\Domain\Money;
use GlpiPlugin\Points\Domain\RuleViolation;
use GlpiPlugin\Points\Domain\TaskRules;

/**
 * Grille « Standard » du cahier des charges (points par heure) : type d'intervention (1 assistance, 2 ordre de travail,
 * 3 urgence) × type de jour (1 semaine, 2 samedi, 3 dimanche et férié).
 *
 * @return callable(int, int): ?int
 */
function standardCosts(int $type = 1): callable
{
    $table = [1 => [1 => '1', 2 => '1.5', 3 => '2'], 2 => [1 => '2', 2 => '3', 3 => '4'], 3 => [1 => '3', 2 => '4.5', 3 => '6']];

    return static fn (int $version, int $daytype): ?int => $version === 1 ? Money::parse($table[$type][$daytype]) : null;
}

test('TaskRules : minutes comptées, quart d\'heure commencé, 0 reste 0', function (): void {
    foreach ([[0, 0], [1, 15], [10, 15], [15, 15], [16, 30], [20, 30], [30, 30], [50, 60], [60, 60], [61, 75]] as [$in, $out]) {
        assertSame($out, TaskRules::countedMinutes($in), "$in min");
    }
    assertThrows(InvalidArgumentException::class, static fn () => TaskRules::countedMinutes(-1));
});

test('TaskRules : minutes comptées depuis des secondes (une seconde entamée compte le quart d\'heure)', function (): void {
    foreach ([[0, 0], [1, 15], [600, 15], [900, 15], [901, 30], [1200, 30], [3000, 60], [3600, 60], [3601, 75]] as [$in, $out]) {
        assertSame($out, TaskRules::countedMinutesFromSeconds($in), "$in s");
    }
    assertThrows(InvalidArgumentException::class, static fn () => TaskRules::countedMinutesFromSeconds(-1));
});

test('TaskRules : type de jour, dimanche ou férié 3, samedi 2, sinon 1', function (): void {
    assertSame(1, TaskRules::dayType('2026-11-10', false), 'mardi');
    assertSame(2, TaskRules::dayType('2026-11-14', false), 'samedi');
    assertSame(3, TaskRules::dayType('2026-11-15', false), 'dimanche');
    assertSame(3, TaskRules::dayType('2026-11-11', true), 'mercredi férié');
    assertSame(3, TaskRules::dayType('2026-11-14', true), 'samedi férié : férié l\'emporte');
    assertSame(3, TaskRules::dayType('2026-11-15 23:59:59', false), 'horodatage');
    assertThrows(InvalidArgumentException::class, static fn () => TaskRules::dayType('2026-02-30', false));
});

test('TaskRules : drapeaux de contrôle', function (): void {
    assertSame([], TaskRules::flags('2026-11-10', false, false));
    assertSame(['saturday'], TaskRules::flags('2026-11-14', false, false));
    assertSame(['sunday'], TaskRules::flags('2026-11-15', false, false));
    assertSame(['holiday'], TaskRules::flags('2026-11-11', true, false));
    assertSame(['sunday', 'holiday', 'off_hours'], TaskRules::flags('2026-11-15', true, true));
    assertSame(['off_hours'], TaskRules::flags('2026-11-10', false, true));
});

test('TaskRules : version applicable = plus grande begin_date <= date, aucune = refus', function (): void {
    $versions = [['id' => 3, 'begin_date' => '2026-06-01'], ['id' => 1, 'begin_date' => '2026-01-01'], ['id' => 2, 'begin_date' => '2026-03-01']];
    assertSame(1, TaskRules::applicableVersion($versions, '2026-01-01'), 'jour de début inclus');
    assertSame(1, TaskRules::applicableVersion($versions, '2026-02-28'));
    assertSame(2, TaskRules::applicableVersion($versions, '2026-03-01'));
    assertSame(3, TaskRules::applicableVersion($versions, '2027-01-01 10:00:00'), 'horodatage');
    assertThrows(RuleViolation::class, static fn () => TaskRules::applicableVersion($versions, '2025-12-31'), 'avant la première version');
    assertThrows(RuleViolation::class, static fn () => TaskRules::applicableVersion([], '2026-01-01'), 'aucune version');
    try {
        TaskRules::applicableVersion([], '2026-01-01');
    } catch (RuleViolation $e) {
        assertSame(RuleViolation::NO_GRID_VERSION, $e->reason);
    }
    assertSame(5, TaskRules::applicableVersion([['id' => 4, 'begin_date' => '2026-01-01'], ['id' => 5, 'begin_date' => '2026-01-01']], '2026-02-01'), 'même date : la plus récente');
});

test('TaskRules : points arrondis au centième, en centièmes entiers', function (): void {
    assertSame(25, TaskRules::points(100, 15, 60));
    assertSame(100, TaskRules::points(200, 30, 60));
    assertSame(100, TaskRules::points(100, 15, 15));
    assertSame(0, TaskRules::points(100, 0, 60));
    assertSame(0, TaskRules::points(0, 60, 60));
    // 0,33 × 15 / 60 = 0,0825 → 0,08 ; 0,34 × 15 / 60 = 0,085 → 0,09 (moitié vers le haut)
    assertSame(8, TaskRules::points(33, 15, 60));
    assertSame(9, TaskRules::points(34, 15, 60));
    assertThrows(InvalidArgumentException::class, static fn () => TaskRules::points(100, 15, 0));
    assertThrows(InvalidArgumentException::class, static fn () => TaskRules::points(-1, 15, 60));
});

test('TaskRules : exemple chiffré du cahier des charges, grille Standard unité 60 = 0,25 + 0,50 + 1,00 + 1,00', function (): void {
    $versions = [['id' => 1, 'begin_date' => '2026-01-01']];
    $rows = [
        [600, '2026-11-10 09:00:00', false, '0.25', 15, 1],
        [1200, '2026-11-10 10:00:00', false, '0.50', 30, 1],
        [3000, '2026-11-10 11:00:00', false, '1.00', 60, 1],
        [1800, '2026-11-15 10:00:00', false, '1.00', 30, 3],
    ];
    $total = 0;
    foreach ($rows as [$seconds, $date, $holiday, $points, $counted, $daytype]) {
        $r = TaskRules::evaluate($seconds, $date, $holiday, false, 60, $versions, standardCosts());
        assertSame($points, Money::format($r['points']), "$seconds s");
        assertSame($counted, $r['counted_minutes']);
        assertSame($daytype, $r['daytype']);
        assertSame(1, $r['gridversions_id']);
        $total += $r['points'];
    }
    assertSame('2.75', Money::format($total));
    assertSame(3725, Money::parse(40) - $total, 'solde 37,25 sur une enveloppe de 40');
    $sunday = TaskRules::evaluate(1800, '2026-11-15 10:00:00', false, false, 60, $versions, standardCosts());
    assertSame(['sunday'], $sunday['flags'], 'dimanche signalé');
    assertSame('2.00', Money::format($sunday['cost']), 'coût unitaire dimanche');
});

test('TaskRules : variante unité 15 = 1 + 2 + 4 + 4', function (): void {
    $versions = [['id' => 1, 'begin_date' => '2026-01-01']];
    $points = [];
    foreach ([[600, '2026-11-10'], [1200, '2026-11-10'], [3000, '2026-11-10'], [1800, '2026-11-15']] as [$seconds, $date]) {
        $points[] = Money::format(TaskRules::evaluate($seconds, $date, false, false, 15, $versions, standardCosts())['points']);
    }
    assertSame(['1.00', '2.00', '4.00', '4.00'], $points);
});

test('TaskRules : durée 0 sans grille, coût absent refusé, version absente refusée', function (): void {
    $zero = TaskRules::evaluate(0, '2026-11-10', false, false, 60, [], static fn (): ?int => null);
    assertSame(0, $zero['points']);
    assertSame(0, $zero['counted_minutes']);
    assertSame(0, $zero['gridversions_id']);
    try {
        TaskRules::evaluate(600, '2026-11-10', false, false, 60, [['id' => 1, 'begin_date' => '2026-01-01']], static fn (): ?int => null);
        throw new AssertionFailed('NO_COST attendu');
    } catch (RuleViolation $e) {
        assertSame(RuleViolation::NO_COST, $e->reason);
    }
    try {
        TaskRules::evaluate(600, '2025-01-01', false, false, 60, [['id' => 1, 'begin_date' => '2026-01-01']], standardCosts());
        throw new AssertionFailed('NO_GRID_VERSION attendu');
    } catch (RuleViolation $e) {
        assertSame(RuleViolation::NO_GRID_VERSION, $e->reason);
    }
});

test('TaskRules : une 2e version datée change le coût selon la date de la tâche', function (): void {
    $versions = [['id' => 1, 'begin_date' => '2026-01-01'], ['id' => 2, 'begin_date' => '2026-07-01']];
    $costs    = static fn (int $version, int $daytype): ?int => $version === 1 ? 100 : 300;
    assertSame(25, TaskRules::evaluate(900, '2026-06-30', false, false, 60, $versions, $costs)['points']);
    assertSame(75, TaskRules::evaluate(900, '2026-07-01', false, false, 60, $versions, $costs)['points']);
});
