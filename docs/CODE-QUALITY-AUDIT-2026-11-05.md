# Repository 8/37 — AI Patch Runner: codekwaliteits- en beveiligingslog

**Auditdatum:** 5 november 2026. Main-baseline: `b57a70a3edd8ace25607504a06ba94705b268b07`. Gewijzigd op branch `quality/backup-path-boundaries-20261105` in [draft PR #4](https://github.com/Yolol100/Patchrunner/pull/4). **Geen merge, productiepluginpatches, uploads van klantbestanden of live sitewijzigingen.**

## Bevestigde beveiligingsfout

**AIPR-001 — symbolische-link ontsnapping bij backup path validation (middel/hoog, afhankelijk van filesystemrechten).**

`AIPR_Admin::is_safe_backup_path()` beperkte paden met een tekstuele prefix `.../uploads/aipr-backups/` en blokkeerde `../`, maar resolveerde geen symlink. Een kwaadwillende of incorrecte submap-symlink in de backupmap kon daarom een bestaand bestand **buiten** de toegestane back-upmap als toegestaan classificeren. De methode wordt aangeroepen vóór rollback-`file_get_contents()` en bij cleanup van oude bestanden. Een volledig uitbuitbare keten in een echte WordPress-installatie is hiermee **niet bewezen**: hiervoor zou controle over de symlink en een bruikbare backupverwijzing nodig zijn.

- **Rood:** uitsluitend geïsoleerde testdata in WP uploads en OS temporary directory. Op WP 6.4/PHP 8.0 en WP 7.1/PHP 8.3 gaf een symlink naar buiten `true`: [CI #41886976439](https://github.com/Yolol100/Patchrunner/actions/runs/41886976439), fout `Unsafe backup path classification: symlink into outside directory`.
- **Reparatie:** alleen de canonieke private `is_safe_backup_path()` veranderd. Combineer oorspronkelijke prefix/traversalcontrole met `realpath()` op uploads-, backup- en oudermap; accepteer bestaande bestanden pas wanneer hun werkelijke pad binnen de vertrouwde base blijft. Nieuwe back-upbestanden mogen alleen onder reeds geverifieerde oudermappen ontstaan.
- **Groen eerste hertest:** [CI #41887231778](https://github.com/Yolol100/Patchrunner/actions/runs/41887231778): 4 van 4 jobs geslaagd, inclusief PHP-syntax en twee echte WordPress-instanties.
- **Extra regressie:** `tests/wp-cli-backup-path-smoke.php` controleert nu zowel legitiem bestaand bestand, legitiem nieuw pad, direct bestandssymlink, mapsymmlink, buitenpad en `../`; eindexact-head na die uitbreiding opnieuw beoordelen.

## Externe vergelijking

- [PHP `realpath()`](https://www.php.net/manual/en/function.realpath.php): de canonieke bestandsnaam volgt symlinks, anders dan stringnormalisatie.
- [WordPress `get_file()`](https://developer.wordpress.org/reference/functions/get_file/): resolveert paden met `realpath()` voordat een bestand wordt gelezen.
- [Smarty security advisory GHSA-f6wf-28g6-769x](https://github.com/smarty-php/smarty/security/advisories/GHSA-f6wf-28g6-769x): een in 2026 gedocumenteerde vergelijkbare klasse van directory-boundarybypass via symlinks; dit is **geen** claim dat Patchrunner dezelfde exploitbaarheid of CVE heeft.
- [WordPress Filesystem API](https://developer.wordpress.org/reference/functions/wp_filesystem/): behouden voor de bestaande, expliciet geautoriseerde schrijfroute.

## Geïnspecteerd en bewust niet veranderd

- Het project heeft een PHP-matrix (8.0, 8.3), runtime-activatie (WP 6.4, WP 7.1), admin-nonces, capability checks en expliciete productieschrijfblokkade met server-side constant én UI-optie.
- De bestaande patchpreview, validatie, back-upregistratie, logging, apply en rollback blijven onaangeraakt; er is geen enkele patch toegepast op andere plugins om een test te 'bewijzen'.
- **Risico:** een `realpath()`-validatie vóór een latere read/write is geen atomaire bescherming tegen een symlink race (TOCTOU). Echt herstel, padhercontrole direct vóór de side effect en filesystem-locking verdienen afzonderlijke review.
- **Risico:** meerdere bestandswijzigingen in één patch zijn niet automatisch transactioneel teruggedraaid wanneer een latere write faalt. Een staging-rollback-/partial-failure-test vereist toestemming en herstelplan.
- **Risico:** webtoegang tot backups in uploads is provider-/webserverafhankelijk; `.htaccess` alleen beschermt niet tegen iedere nginx-configuratie. Niet op productie geverifieerd.
- **Nog niet uitgevoerd:** toepassing van echte patch, herstel van pluginbestanden, nginx/Apache staging, multisite, raceproeven, grote patches, browser- en toetsenbord QA, WP Plugin Check, distributie-ZIP na extractie.
- Geen willekeurige code, CSS of oude hooks verwijderd. Geen pluginversie gewijzigd (bron blijft `3.1.7`).

## Gate / rollback

Exact-head CI van de definitieve PR moet groen zijn; daarna code-review en gecontroleerde staging filesystem-/backupacceptatie. Sluiten van [PR #4](https://github.com/Yolol100/Patchrunner/pull/4) draait uitsluitend voorgestelde bronwijzigingen terug. `main` en WordPress-websites zijn ongewijzigd.
