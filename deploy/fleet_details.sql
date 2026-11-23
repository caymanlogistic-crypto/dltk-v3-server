CREATE TABLE IF NOT EXISTS fleet_trailer_profiles (
    plate_key VARCHAR(28) COLLATE utf8mb4_0900_ai_ci PRIMARY KEY,
    plate VARCHAR(28) NOT NULL,
    brand VARCHAR(160) NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS fleet_vehicle_trailers (
    vehicle_id BIGINT PRIMARY KEY,
    trailer_key VARCHAR(28) COLLATE utf8mb4_0900_ai_ci NOT NULL,
    FOREIGN KEY (vehicle_id) REFERENCES fleet_vehicle_profiles(entry_id),
    FOREIGN KEY (trailer_key) REFERENCES fleet_trailer_profiles(plate_key)
);

CREATE TABLE IF NOT EXISTS fleet_driver_documents (
    driver_id BIGINT PRIMARY KEY,
    passport VARCHAR(500) NOT NULL DEFAULT '',
    license_number VARCHAR(80) NOT NULL DEFAULT '',
    license_issued DATE NULL,
    license_expires DATE NULL,
    revision INT NOT NULL DEFAULT 1,
    FOREIGN KEY (driver_id) REFERENCES fleet_driver_profiles(entry_id)
);
