ALTER TABLE %PREFIX%_event_attendance_trackers ADD COLUMN eat_cat_id integer unsigned NULL;
ALTER TABLE %PREFIX%_event_attendance_trackers ADD COLUMN eat_source_start timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE %PREFIX%_event_attendance_trackers ADD CONSTRAINT %PREFIX%_fk_eat_calendar FOREIGN KEY (eat_cat_id) REFERENCES %PREFIX%_categories (cat_id) ON DELETE RESTRICT ON UPDATE RESTRICT;
CREATE INDEX %PREFIX%_idx_eat_calendar ON %PREFIX%_event_attendance_trackers (eat_cat_id);
