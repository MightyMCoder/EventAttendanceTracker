# Plan: Anwesenheit bei Terminen

## Ziel und Abgrenzung

Das Plugin `event-attendance` ergänzt das aktivierte Kernmodul **Termine** von Admidio 6.0. Seine
zentrale Konfigurationseinheit ist ein **Tracker**: Ein Tracker fasst gezielt ausgewählte Termine
oder Terminserien und den dafür geltenden Personenkreis aus Gruppen und Rollen zusammen. Er erfasst
den tatsächlichen Status einer Person je konkretem Termin und wertet diese Daten aus. Er ersetzt
weder die vorhandene Anmeldung zum Termin noch deren Rollenmitgliedschaft.

Der erste funktionsfähige Umfang umfasst diese Status:

| Status | Bedeutung |
| --- | --- |
| `present` | Person war anwesend. |
| `excused` | Person hat sich entschuldigt. |
| `absent` | Person fehlte unentschuldigt. |
| `unknown` | Person gehört zum Sollkreis, ist vor Terminende aber noch nicht erfasst. Dieser Wert wird berechnet, nicht gespeichert. |

Für den Termin selbst gibt es zusätzlich `cancelled` (entfallen) und `active`. Ein entfallener
Termin wird nicht als Anwesenheitstermin gezählt und ist in Auswertungen standardmäßig getrennt
sichtbar. Bereits eingegebene Daten bleiben erhalten.

Sobald ein konkreter Termin beendet ist (`dat_end`, bei fehlendem Ende `dat_begin`), werden noch
offene Sollkreis-Personen automatisch und dauerhaft auf `absent` gesetzt. Dadurch gibt es danach
keinen Status `unknown` mehr. Entschuldigte und ausdrücklich erfasste Personen bleiben unverändert.

## Bestehende Admidio-Basis

- Ein Termin ist ein Datensatz in `adm_events`; seine stabile öffentliche Referenz ist `dat_uuid`.
- Die Anmeldung zum Termin wird über die dem Termin zugeordnete Rolle `dat_rol_id` und
  `adm_members` abgebildet. `mem_comment`, `mem_count_guests` und `mem_approved` bleiben Eigentum
  des Kernmoduls und werden nicht als Anwesenheitsstatus umgedeutet.
- Wiederkehrende Termine liegen als konkrete Event-Datensätze vor. Der Kern kennt für Vorkommen
  bereits `dat_recurrence_status = cancelled`; die Plugin-Markierung ergänzt dies für einzelne wie
  auch für Serienvorkommen, ohne Kerntermine zu löschen oder zu verändern.
- Der Plugin-Manager erkennt das lokale Verzeichnis an `plugin.json` und installiert die
  zugehörigen Komponenten, Einstellungen und späteren Datenbankskripte je Organisation.

## Datenmodell (Phase 1)

Das Plugin erstellt eigene Tabellen mit dem Präfix `%PREFIX%_event_attendance_`; es erweitert
keine Admidio-Kerntabelle.

1. `event_attendance_trackers`
   - `eat_id`, `eat_uuid`, `eat_name`, `eat_description`, `eat_active` sowie Auditspalten.
   - Ein Tracker gehört zur aktuellen Organisation; seine Bezeichnung ist innerhalb dieser
     Organisation eindeutig.
2. `event_attendance_tracker_events`
   - Verbindet einen Tracker mit einem einzelnen `adm_events.dat_id` oder einer
     `adm_event_recurrences.evr_id`. Beim Lesen einer Serie werden die konkreten, zur Serie
     gehörenden Event-Vorkommen aufgelöst. Ein Termin darf mehreren Trackern angehören.
3. `event_attendance_tracker_roles`
   - Verbindet einen Tracker mit den ausgewählten Admidio-Gruppen oder -Rollen (`rol_id`). Der
     Sollkreis ist die eindeutige Vereinigung ihrer zu Terminbeginn aktiven Mitglieder.
   - Beim ersten Öffnen der Erfassung, spätestens beim automatischen Abschluss, wird dieser
     Sollkreis pro Tracker und Termin als Snapshot festgeschrieben. Spätere Gruppenänderungen
     verändern damit keine historische Auswertung.
4. `event_attendance_events`
   - `eae_id`, `eae_tracker_id`, `eae_dat_id` (zusammen eindeutig),
     `eae_cancelled` (boolean), `eae_cancelled_at`, `eae_cancelled_by_usr_id`,
     `eae_cancelled_note` und die üblichen Auditspalten.
   - Ein fehlender Datensatz bedeutet `active`; dadurch entstehen keine Daten für nicht verwendete
     Tracker-Termin-Paare. Die eindeutige Kombination verhindert Doppelmarkierungen.
5. `event_attendance_records`
   - `ear_id`, `ear_tracker_event_id`, `ear_usr_id`, `ear_status` (`present`, `excused`, `absent`),
     `ear_note` sowie Auditspalten.
   - Eindeutiger Index auf `(ear_tracker_event_id, ear_usr_id)` und Indizes für Tracker-Termin,
     Benutzer und Status.
   - Eintragungen beziehen sich ausschließlich auf reale Benutzer, nie auf zusätzliche Gäste.
6. `event_attendance_expected_users`
   - Speichert den Sollkreis-Snapshot (`Tracker-Termin`, `Benutzer`) ohne Anwesenheitsstatus.
     So ist `unknown` bis Terminende berechenbar, und neue Rollenmitglieder erscheinen nicht
     rückwirkend in gestarteten oder abgeschlossenen Terminen.

Die Installation erfolgt in `db_scripts/install.sql`; Änderungen folgen als fortlaufende
`db_scripts/update_<major>_<minor>.xml`. Vor dem Implementieren sind die aktuellen
Plugin-Installations- und Deinstallationsabläufe zu prüfen. Die Deinstallation entfernt nur diese
Plugin-Tabellen. Ein Löschhook für `event_deleted` bereinigt zugehörige Plugindaten, falls ein
Kerntermin physisch gelöscht wird.

## Berechtigungsmodell

- Lesen und Auswerten: nur angemeldete Benutzer mit Sichtrecht auf den jeweiligen Termin.
- Erfassen, Ändern und als entfallen markieren: Administratoren sowie Leiter der
  Teilnahme-Rolle (`dat_rol_id`); bei Terminen ohne Teilnahme-Rolle zunächst ausschließlich
  Administratoren.
- Jede schreibende Plugin-Route lädt den Termin anhand der UUID erneut, prüft das Sicht- und
  Bearbeitungsrecht serverseitig und validiert das CSRF-Token. Formfelder, übergebene Benutzer-IDs
  und ausgeblendete Schaltflächen gelten nicht als Berechtigungsnachweis.
- Der Sollkreis kommt ausschließlich aus den am Tracker konfigurierten Gruppen und Rollen, nicht
  automatisch aus der Teilnahme-Rolle des Kerntermins. Ein Mitglied mehrerer ausgewählter Rollen
  erscheint nur einmal. Die Mitgliedschaft wird zum Beginn eines künftigen Termins ermittelt und
  dann als Snapshot gespeichert: Wer einer Tracker-Rolle später beitritt, erscheint ab dem nächsten
  noch nicht begonnenen Termin; für ältere Termine wird kein nachträglicher Eintrag erzeugt.
- Mitglieder dürfen sich nur dann selbst als `excused` erfassen, wenn die Einstellung
  `event_attendance_members_may_excuse_themselves` aktiviert ist und sie zum Sollkreis des
  konkreten Tracker-Termins gehören. Die zentrale Service-Methode erzwingt dabei, dass nur der
  eigene Snapshot-Eintrag den Status `excused` erhält.
- Tracker verwalten neben Administratoren auch Mitglieder der in
  `event_attendance_tracker_manager_roles` ausgewählten Gruppen und Rollen.

## Oberfläche und Routen (Phase 2)

Die Plugin-Seite `modules/index.php` wird als normaler Admidio-Moduleinstieg umgesetzt. Sie bietet:

1. Tracker-Verwaltung: Name, Beschreibung, aktive/inaktive Tracker, Auswahl einzelner Termine und
   Terminserien sowie Mehrfachauswahl der Gruppen und Rollen für den Sollkreis.
2. Terminliste je Tracker mit Zeitraum-, Kategorie- und Statusfilter sowie Hinweis „entfallen“.
3. Erfassungsseite pro Tracker und Termin: Sollkreis-Snapshot, Statusauswahl, optionaler Vermerk, Zähler und
   Sammelaktion „übrige als abwesend“. Entfallene Termine sind schreibgeschützt, bis die
   Entfall-Markierung ausdrücklich aufgehoben wird.
4. Entfall-Dialog mit CSRF-Schutz, optionaler Begründung und bei Serien Auswahl „dieser Termin"
   oder „diese und künftige“; die Serienauswahl arbeitet auf den bereits konkreten
   `adm_events`-Vorkommen. Standardmäßig betrifft dies nur den Tracker. Die allgemeine
   Plugin-Einstellung `event_attendance_sync_core_cancellation` kann später optional die
   Kernabsage synchronisieren; sie ist standardmäßig deaktiviert.
5. Auswertungsseite mit Filtern für Tracker, Zeitraum, Kategorie, Rolle und Personenstatus sowie CSV-Export.

Präsentation und Formulare verwenden `PagePresenter`, `FormPresenter` und eigene Smarty-Templates;
keine zusammengesetzten HTML-Strings in Presenter-Klassen. Tabellen mit vielen Personen werden als
serverseitiges DataTables-Endpunkt umgesetzt: Eingaben validieren, Sortierspalten whitelisten,
`draw`, `recordsTotal`, `recordsFiltered` und `data` liefern und dieselben Rechte wie die Seite
davor prüfen.

## Integration und Umsetzungsschritte

1. **Grundlage:** Manifest, Sprachdateien und Verzeichnisstruktur vervollständigen; lokale
   Installation über Administration → Erweiterungen testen.
2. **Persistenz:** Die Installations- und Deinstallationsskripte für Tracker, Termin-/Serien- und
   Rollen-Zuordnungen sowie Anwesenheitsdaten sind angelegt. Als Nächstes folgen die
   Entity-/Repository-/Service-Klassen im eigenen Namespace. Alle Werte werden über
   `queryPrepared()` gebunden und Mehrfachänderungen transaktional ausgeführt.
3. **Terminintegration:** `event_deleted` zur Bereinigung registrieren. Bei Leselogik sowohl die
   Plugin-Entfallmarkierung als auch `dat_recurrence_status = cancelled` berücksichtigen. Den
   Kerntermin nicht überschreiben.
4. **Tracker-Konfiguration:** CRUD für Tracker, Auswahl von Terminen/Serien und Gruppen/Rollen
   sowie Auflösen der Serie zu konkreten Terminvorkommen implementieren.
5. **Erfassung und Abschluss:** Modulroute, Presenter, Smarty-Formulare, Rechteprüfungen und
   atomisches Speichern der Status umsetzen. Der idempotente Service `closeDueEvents()` legt den
   Sollkreis-Snapshot an und speichert für alle nach Terminende noch offenen Personen `absent`.
   Der Plugin-CLI-Befehl `event-attendance:close-due-events` läuft per Cron und wird beim Aufruf von Erfassung oder
   Auswertung zusätzlich ausgeführt, damit die Daten auch ohne Cron aktuell bleiben.
6. **Auswertungen:** Aggregationen pro Tracker, Termin, Person, Rolle und Zeitraum sowie CSV-Export
   bauen. Quoten verwenden nur nicht entfallene Termine.
7. **Politur:** Deutsche und englische Texte, Zugänglichkeit, leere Zustände und dokumentierte
   Installation für die lokale Umgebung.

## Tests und Abnahmekriterien

- Unit-Tests für Statusübergänge, Quoten, Tracker-Zuordnung, Sollkreis-Snapshots und den
  automatischen Abschluss.
- Integrations-Tests für Installation/Update, Organisationsabgrenzung, eindeutige Einträge,
  Rechte, CSRF, Löschung eines Kerntermins und Serien-Entfall.
- Workflow-Test: Tracker aus Rollen konfigurieren → Termin/Serie zuordnen → anwesend/entschuldigt
  erfassen → Terminende überschreiten → offene Einträge sind unentschuldigt → Termin entfallen
  lassen → Auswertung und CSV prüfen.
- Akzeptanz: Ein unberechtigter Benutzer kann weder Personenstatus noch Entfall verändern; ein
  berechtigter Leiter kann nur sichtbare und zu seinem Termin gehörige Daten ändern; ein
  entfallener Termin verfälscht keine Anwesenheitsquote.

## Noch zu entscheiden

1. Sollen Tracker-Verwalter durch jede Mitgliedschaft in einer ausgewählten Rolle berechtigt sein
   (aktuelle Festlegung) oder nur durch die Leiterrolle dieser Gruppe?

## Version 2.0 migration

Version 2.0 is the first Admidio 6 release line of Event Attendance Tracker. New installations
receive the complete current schema from `db_scripts/install.sql`. Every later schema change is
added both to that installation schema and to a versioned XML update step for already installed
2.x plugin versions.

Existing 1.x installations use a dedicated legacy bridge because Admidio 5 did not register
third-party plugins through the Admidio 6 component manager. The complete `install.sql` is
idempotent for the 1.0.0-beta.2 table schema, so native installation registers the component
without replacing existing tracker, relation, snapshot, record or scheduler data. During that
installation the bridge transfers each organization's legacy JSON settings to native plugin
preferences exactly once. The legacy settings table is retained until uninstallation as a
recoverable source; the native migration marker prevents it from overwriting later v2 changes.

Versioned XML files remain exclusively responsible for structural migrations of already registered
v2 installations. The v1 bridge is part of the 2.0 release acceptance criteria; it must never
require users to reinstall the plugin or discard recorded attendance.
