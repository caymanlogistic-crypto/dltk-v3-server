CREATE TABLE IF NOT EXISTS unload_points (
    entry_id BIGINT PRIMARY KEY,
    client_id BIGINT NULL,
    client_label VARCHAR(160) NOT NULL DEFAULT '',
    address TEXT NOT NULL,
    schedule TEXT NOT NULL,
    instruction TEXT NOT NULL,
    CONSTRAINT unload_points_entry_fk FOREIGN KEY (entry_id) REFERENCES catalog_entries(id),
    CONSTRAINT unload_points_client_fk FOREIGN KEY (client_id) REFERENCES catalog_entries(id),
    INDEX unload_points_by_client (client_id)
);

CREATE TABLE IF NOT EXISTS unload_point_contacts (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    entry_id BIGINT NOT NULL,
    name VARCHAR(160) NOT NULL DEFAULT '',
    phone VARCHAR(100) NOT NULL DEFAULT '',
    email VARCHAR(254) NOT NULL DEFAULT '',
    original TEXT NOT NULL,
    is_primary BOOLEAN NOT NULL DEFAULT FALSE,
    CONSTRAINT unload_contacts_point_fk FOREIGN KEY (entry_id) REFERENCES unload_points(entry_id),
    INDEX unload_contacts_for_point (entry_id, is_primary)
);

CREATE TABLE IF NOT EXISTS unload_point_origins (
    record_id BIGINT PRIMARY KEY,
    entry_id BIGINT NOT NULL,
    CONSTRAINT unload_origins_record_fk FOREIGN KEY (record_id) REFERENCES records(id),
    CONSTRAINT unload_origins_point_fk FOREIGN KEY (entry_id) REFERENCES unload_points(entry_id),
    INDEX unload_origins_for_point (entry_id)
);
