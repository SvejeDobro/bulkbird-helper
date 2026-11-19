<?php
/**
 * Testovi stvarnim HTTP zahtjevima prema testnim stranicama (NOVA / STARA).
 *
 * Odabir stranice:   BBH_TARGET=NEW (zadano) ili BBH_TARGET=OLD
 * Ključevi:          ~/.config/bulkbird-helper/test.env (izvan repozitorija; vidi docs/scenarios-faza0.md)
 * Opis stranica:     ~/.config/bulkbird-helper/sites.env (izvan repozitorija; vidi sites.env.example)
 * Očekivana verzija: BBH_EXPECT_VERSION (npr. 0.1.0); ako nije zadana, uzima se iz bulkbird-helper.php
 *
 * Testovi nikad ne ispisuju ključeve: poruke o padu sadrže samo status, kod greške i nazive zaglavlja.
 *
 * @package Bulkbird\Helper\Tests
 */

declare( strict_types=1 );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/tools/sites.php';
require_once __DIR__ . '/E2ECase.php';
