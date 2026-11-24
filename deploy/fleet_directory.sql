CREATE TABLE IF NOT EXISTS fleet_driver_profiles (
    entry_id BIGINT PRIMARY KEY,
    surname VARCHAR(65) NOT NULL,
    given_name VARCHAR(45) NOT NULL,
    patronymic VARCHAR(45) NOT NULL DEFAULT '',
    phone VARCHAR(100) NOT NULL DEFAULT '',
    FOREIGN KEY (entry_id) REFERENCES catalog_entries(id)
);

CREATE TABLE IF NOT EXISTS fleet_vehicle_profiles (
    entry_id BIGINT PRIMARY KEY,
    brand VARCHAR(160) NOT NULL DEFAULT '',
    vehicle_type VARCHAR(100) NOT NULL DEFAULT '',
    driver_id BIGINT NULL,
    FOREIGN KEY (entry_id) REFERENCES catalog_entries(id),
    FOREIGN KEY (driver_id) REFERENCES fleet_driver_profiles(entry_id),
    INDEX fleet_vehicle_driver (driver_id)
);

CREATE TABLE IF NOT EXISTS fleet_trip_origins (
    record_id BIGINT NOT NULL,
    field_key VARCHAR(8) NOT NULL,
    entry_id BIGINT NOT NULL,
    PRIMARY KEY (record_id, field_key),
    FOREIGN KEY (record_id) REFERENCES records(id),
    FOREIGN KEY (entry_id) REFERENCES catalog_entries(id),
    INDEX fleet_trip_entry (entry_id)
);
