<?php

declare(strict_types=1);

/**
 * Garde-fous de lecture du code du cloisonnement (lot f) ; le comportement réel est au banc (scenario_cloisonnement.py).
 */
$src = static fn (string $file): string => (string) file_get_contents(__DIR__ . '/../../' . $file);

test('Interface simplifiée : droit ajouté à Profile::$helpdesk_rights, page en STRATEGY_HELPDESK_ACCESS, entrée de menu, droit contrôlé', function () use ($src): void {
    $setup = $src('setup.php');
    assertTrue(str_contains($setup, '\\Profile::$helpdesk_rights[] = Rights::BALANCE'), 'sans cela un profil helpdesk perd plugin_points_balance à l\'ouverture de session');
    assertTrue(str_contains($setup, "'#^/front/points\\.php(/.*)?\$#', Firewall::STRATEGY_HELPDESK_ACCESS"), 'pare-feu de la page (et de ses sous-chemins)');
    assertTrue(str_contains($setup, 'Hooks::HELPDESK_MENU_ENTRY]') && str_contains($setup, 'Hooks::HELPDESK_MENU_ENTRY_ICON]'), 'entrée de menu et icône');
    assertTrue(preg_match('/if \(Session::haveRight\(Rights::BALANCE, READ\)\) \{\s*\$PLUGIN_HOOKS\[Hooks::HELPDESK_MENU_ENTRY\]/', $setup) === 1, 'entrée seulement avec le droit de lecture des soldes');
    assertTrue(str_contains($src('front/points.php'), 'Session::checkRight(Rights::BALANCE, READ)'), 'la page contrôle le droit');
});

test('Page simplifiée : lecture seule, entités actives, registre sans auteur ni commentaire', function () use ($src): void {
    $code = $src('src/Simplified.php');
    assertTrue(str_contains($code, "getEntitiesRestrictCriteria(\$table, 'entities_id', '', false)"), 'contrats des entités actives, sans récursivité');
    assertTrue(str_contains($code, 'ledgerView($contract[\'id\'], false)'), 'registre sans notes internes');
    assertTrue(str_contains($code, 'Rights::BALANCE, READ'), 'droit de lecture des soldes');
    assertSame(0, preg_match('/\$DB->(insert|update|delete)/', $code), 'aucune écriture');
    $template = $src('templates/simplified.html.twig');
    assertSame(0, preg_match('/<form|<input|<button|\|raw/', $template), 'aucun formulaire ni |raw');
});

test('Écritures du journal par formulaire : accès à l\'entité propre du contrat (contrat récursif d\'une entité mère)', function () use ($src): void {
    $code = $src('src/Contract.php');
    assertTrue(preg_match('/function requireContract.*?!self::haveEntityAccess\(\$contract\)/s', $code) === 1, 'requireContract contrôle l\'entité du contrat');
    assertTrue(preg_match('/function getTabNameForItem.*?self::haveEntityAccess\(\$item\)/s', $code) === 1, 'onglet masqué hors entité du contrat');
    assertTrue(preg_match('/function displayTabContentForItem.*?self::haveEntityAccess\(\$item\)/s', $code) === 1, 'contenu de l\'onglet refusé hors entité du contrat');
    foreach (['carryover', 'correction', 'credit', 'envelope', 'contract'] as $form) {
        assertTrue(str_contains($src("front/$form.form.php"), 'Contract::requireContract('), "$form.form.php passe par requireContract");
    }
});

test('Débit : seul un contrat de l\'entité du ticket, ou récursif d\'une entité mère, est candidat', function () use ($src): void {
    assertTrue(preg_match('/getEntitiesRestrictCriteria\(\'glpi_contracts\', \'entities_id\', \$ctx\[\'entities_id\'\], true\)/', $src('src/Billing.php')) === 1, 'Billing::context restreint les contrats candidats par l\'entité du ticket');
});

test('Champs internes du journal : vidés sans droit interne (getItem), options de recherche conditionnées, onglet', function () use ($src): void {
    $guard = $src('src/InternalFields.php');
    assertTrue(str_contains($guard, 'Rights::ENVELOPE, READ') && str_contains($guard, 'Rights::REVIEW, READ'), 'droit interne = envelope READ ou review READ');
    $op = $src('src/Operation.php');
    assertTrue(str_contains($op, 'function post_getFromDB') && str_contains($op, "'comment' => ''") && str_contains($op, "'users_id' => 0") && str_contains($op, "'operations_id_origin' => 0"), 'opération : champs vidés');
    assertTrue(preg_match('/if \(InternalFields::allowed\(\)\) \{[^}]*\'id\' => \'6\'[^}]*\'id\' => \'8\'/s', $op) === 1, 'options commentaire et auteur conditionnées');
    assertTrue(str_contains($src('src/Envelope.php'), 'InternalFields::blank($this->fields'), 'enveloppe : auteur vidé');
    assertTrue(str_contains($src('src/Contract.php'), 'self::ledgerView($id, InternalFields::allowed())'), 'onglet du contrat sans notes internes');
});

test('Champs internes : unsetUndisclosedFields redéfini ; contrat à points : entité figée ; forfait : contrat candidat', function () use ($src): void {
    assertTrue(preg_match('/function unsetUndisclosedFields.*?parent::unsetUndisclosedFields.*?InternalFields::unset\(\$fields, \[.comment., .details., .users_id., .operations_id_origin.\]\)/s', $src('src/Operation.php')) === 1, 'opération');
    assertTrue(preg_match('/function unsetUndisclosedFields.*?InternalFields::unset\(\$fields, \[.users_id.\]\)/s', $src('src/Envelope.php')) === 1, 'enveloppe');
    assertTrue(str_contains($src('setup.php'), 'Contract::class, \'contractPreUpdate\''), 'hook PRE_ITEM_UPDATE du contrat');
    assertTrue(preg_match('/function contractPreUpdate.*?entities_id.*?\$contract->input = false/s', $src('src/Contract.php')) === 1, 'refus du changement d\'entité');
    $flatrate = $src('src/Flatrate.php');
    assertTrue(preg_match('/FlatrateRules::DEBITED\);\s*self::assertCandidateContract\(\$row\);.*?Ledger::debit\(/s', $flatrate) === 1, 'contrat candidat revérifié avant Ledger::debit');
    assertTrue(str_contains($flatrate, '[\'candidates\'][(int) $row[\'contracts_id\']]'), 'candidat de Billing::context');
});
