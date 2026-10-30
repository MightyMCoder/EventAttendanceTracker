CREATE TABLE %PREFIX%_event_attendance_trackers
(
    eat_id                  integer unsigned NOT NULL AUTO_INCREMENT,
    eat_org_id              integer unsigned NOT NULL,
    eat_uuid                varchar(36) NOT NULL,
    eat_name                varchar(100) NOT NULL,
    eat_description         text,
    eat_cat_id              integer unsigned,
    eat_source_start        timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    eat_active              boolean NOT NULL DEFAULT true,
    eat_usr_id_create       integer unsigned,
    eat_timestamp_create    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    eat_usr_id_change       integer unsigned,
    eat_timestamp_change    timestamp NULL DEFAULT NULL,
    PRIMARY KEY (eat_id),
    CONSTRAINT %PREFIX%_fk_eat_org FOREIGN KEY (eat_org_id) REFERENCES %PREFIX%_organizations (org_id) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT %PREFIX%_fk_eat_calendar FOREIGN KEY (eat_cat_id) REFERENCES %PREFIX%_categories (cat_id) ON DELETE RESTRICT ON UPDATE RESTRICT
);
CREATE UNIQUE INDEX %PREFIX%_idx_eat_uuid ON %PREFIX%_event_attendance_trackers (eat_uuid);
CREATE UNIQUE INDEX %PREFIX%_idx_eat_org_name ON %PREFIX%_event_attendance_trackers (eat_org_id, eat_name);
CREATE INDEX %PREFIX%_idx_eat_calendar ON %PREFIX%_event_attendance_trackers (eat_cat_id);

CREATE TABLE %PREFIX%_event_attendance_tracker_events
(
    ete_id                  integer unsigned NOT NULL AUTO_INCREMENT,
    ete_tracker_id          integer unsigned NOT NULL,
    ete_dat_id              integer unsigned NOT NULL,
    PRIMARY KEY (ete_id),
    CONSTRAINT %PREFIX%_fk_ete_tracker FOREIGN KEY (ete_tracker_id) REFERENCES %PREFIX%_event_attendance_trackers (eat_id) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT %PREFIX%_fk_ete_event FOREIGN KEY (ete_dat_id) REFERENCES %PREFIX%_events (dat_id) ON DELETE CASCADE ON UPDATE RESTRICT
);
CREATE UNIQUE INDEX %PREFIX%_idx_ete_tracker_event ON %PREFIX%_event_attendance_tracker_events (ete_tracker_id, ete_dat_id);

CREATE TABLE %PREFIX%_event_attendance_tracker_roles
(
    etr_id                  integer unsigned NOT NULL AUTO_INCREMENT,
    etr_tracker_id          integer unsigned NOT NULL,
    etr_rol_id              integer unsigned NOT NULL,
    PRIMARY KEY (etr_id),
    CONSTRAINT %PREFIX%_fk_etr_tracker FOREIGN KEY (etr_tracker_id) REFERENCES %PREFIX%_event_attendance_trackers (eat_id) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT %PREFIX%_fk_etr_role FOREIGN KEY (etr_rol_id) REFERENCES %PREFIX%_roles (rol_id) ON DELETE RESTRICT ON UPDATE RESTRICT
);
CREATE UNIQUE INDEX %PREFIX%_idx_etr_tracker_role ON %PREFIX%_event_attendance_tracker_roles (etr_tracker_id, etr_rol_id);

CREATE TABLE %PREFIX%_event_attendance_events
(
    eae_id                  integer unsigned NOT NULL AUTO_INCREMENT,
    eae_tracker_id          integer unsigned NOT NULL,
    eae_dat_id              integer unsigned NOT NULL,
    eae_cancelled           boolean NOT NULL DEFAULT false,
    eae_cancelled_note      varchar(1000),
    eae_cancelled_by_usr_id integer unsigned,
    eae_cancelled_at        timestamp NULL DEFAULT NULL,
    eae_snapshot_at         timestamp NULL DEFAULT NULL,
    PRIMARY KEY (eae_id),
    CONSTRAINT %PREFIX%_fk_eae_tracker FOREIGN KEY (eae_tracker_id) REFERENCES %PREFIX%_event_attendance_trackers (eat_id) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT %PREFIX%_fk_eae_event FOREIGN KEY (eae_dat_id) REFERENCES %PREFIX%_events (dat_id) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT %PREFIX%_fk_eae_cancelled_by FOREIGN KEY (eae_cancelled_by_usr_id) REFERENCES %PREFIX%_users (usr_id) ON DELETE SET NULL ON UPDATE RESTRICT
);
CREATE UNIQUE INDEX %PREFIX%_idx_eae_tracker_event ON %PREFIX%_event_attendance_events (eae_tracker_id, eae_dat_id);

CREATE TABLE %PREFIX%_event_attendance_expected_users
(
    eau_id                  integer unsigned NOT NULL AUTO_INCREMENT,
    eau_tracker_event_id    integer unsigned NOT NULL,
    eau_usr_id              integer unsigned NOT NULL,
    PRIMARY KEY (eau_id),
    CONSTRAINT %PREFIX%_fk_eau_tracker_event FOREIGN KEY (eau_tracker_event_id) REFERENCES %PREFIX%_event_attendance_events (eae_id) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT %PREFIX%_fk_eau_user FOREIGN KEY (eau_usr_id) REFERENCES %PREFIX%_users (usr_id) ON DELETE RESTRICT ON UPDATE RESTRICT
);
CREATE UNIQUE INDEX %PREFIX%_idx_eau_event_user ON %PREFIX%_event_attendance_expected_users (eau_tracker_event_id, eau_usr_id);

CREATE TABLE %PREFIX%_event_attendance_records
(
    ear_id                  integer unsigned NOT NULL AUTO_INCREMENT,
    ear_tracker_event_id    integer unsigned NOT NULL,
    ear_usr_id              integer unsigned NOT NULL,
    ear_status              varchar(16) NOT NULL,
    ear_note                varchar(1000),
    ear_usr_id_create       integer unsigned,
    ear_timestamp_create    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ear_usr_id_change       integer unsigned,
    ear_timestamp_change    timestamp NULL DEFAULT NULL,
    PRIMARY KEY (ear_id),
    CONSTRAINT %PREFIX%_fk_ear_tracker_event FOREIGN KEY (ear_tracker_event_id) REFERENCES %PREFIX%_event_attendance_events (eae_id) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT %PREFIX%_fk_ear_user FOREIGN KEY (ear_usr_id) REFERENCES %PREFIX%_users (usr_id) ON DELETE RESTRICT ON UPDATE RESTRICT
);
CREATE UNIQUE INDEX %PREFIX%_idx_ear_event_user ON %PREFIX%_event_attendance_records (ear_tracker_event_id, ear_usr_id);
CREATE INDEX %PREFIX%_idx_ear_status ON %PREFIX%_event_attendance_records (ear_status);

CREATE TABLE %PREFIX%_event_attendance_locks
(
    eal_id                  integer unsigned NOT NULL AUTO_INCREMENT,
    eal_tracker_id          integer unsigned NOT NULL,
    eal_event_uuid          varchar(36) NOT NULL,
    eal_usr_id              integer unsigned NOT NULL,
    eal_token               varchar(64) NOT NULL,
    eal_expires_at          timestamp NOT NULL,
    eal_timestamp_change    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (eal_id),
    CONSTRAINT %PREFIX%_fk_eal_tracker FOREIGN KEY (eal_tracker_id) REFERENCES %PREFIX%_event_attendance_trackers (eat_id) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT %PREFIX%_fk_eal_user FOREIGN KEY (eal_usr_id) REFERENCES %PREFIX%_users (usr_id) ON DELETE RESTRICT ON UPDATE RESTRICT
);
CREATE UNIQUE INDEX %PREFIX%_idx_eal_tracker_event ON %PREFIX%_event_attendance_locks (eal_tracker_id, eal_event_uuid);
CREATE INDEX %PREFIX%_idx_eal_expires_at ON %PREFIX%_event_attendance_locks (eal_expires_at);

CREATE TABLE %PREFIX%_event_attendance_excuses
(
    eax_id                  integer unsigned NOT NULL AUTO_INCREMENT,
    eax_tracker_id          integer unsigned NOT NULL,
    eax_usr_id              integer unsigned NOT NULL,
    eax_date_from           date NOT NULL,
    eax_date_until          date NULL,
    eax_created_at          timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    eax_revoked_at          timestamp NULL DEFAULT NULL,
    PRIMARY KEY (eax_id),
    CONSTRAINT %PREFIX%_fk_eax_tracker FOREIGN KEY (eax_tracker_id) REFERENCES %PREFIX%_event_attendance_trackers (eat_id) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT %PREFIX%_fk_eax_user FOREIGN KEY (eax_usr_id) REFERENCES %PREFIX%_users (usr_id) ON DELETE RESTRICT ON UPDATE RESTRICT
);
CREATE INDEX %PREFIX%_idx_eax_tracker_user ON %PREFIX%_event_attendance_excuses (eax_tracker_id, eax_usr_id);

CREATE TABLE %PREFIX%_event_attendance_scheduler
(
    eas_id          integer unsigned NOT NULL AUTO_INCREMENT,
    eas_org_id      integer unsigned NOT NULL,
    eas_name        varchar(100) NOT NULL,
    eas_last_run_at timestamp NULL DEFAULT NULL,
    PRIMARY KEY (eas_id)
);
CREATE UNIQUE INDEX %PREFIX%_idx_eas_org_name ON %PREFIX%_event_attendance_scheduler (eas_org_id, eas_name);
INSERT INTO %PREFIX%_event_attendance_scheduler (eas_org_id, eas_name)
SELECT org_id, 'close-due-events' FROM %PREFIX%_organizations;

CREATE TABLE %PREFIX%_event_attendance_settings
(
    eas_org_id      integer unsigned NOT NULL,
    eas_name        varchar(100) NOT NULL,
    eas_value       text,
    PRIMARY KEY (eas_org_id, eas_name),
    CONSTRAINT %PREFIX%_fk_eas_settings_org FOREIGN KEY (eas_org_id) REFERENCES %PREFIX%_organizations (org_id) ON DELETE CASCADE ON UPDATE RESTRICT
);
