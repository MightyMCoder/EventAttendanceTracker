CREATE TABLE %PREFIX%_event_attendance_settings
(
    eas_org_id      integer unsigned NOT NULL,
    eas_name        varchar(100) NOT NULL,
    eas_value       text,
    PRIMARY KEY (eas_org_id, eas_name),
    CONSTRAINT %PREFIX%_fk_eas_settings_org FOREIGN KEY (eas_org_id) REFERENCES %PREFIX%_organizations (org_id) ON DELETE CASCADE ON UPDATE RESTRICT
);
