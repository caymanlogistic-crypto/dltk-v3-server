CREATE TABLE IF NOT EXISTS fleet_trip_documents (
    record_id BIGINT PRIMARY KEY,
    document_text TEXT NOT NULL,
    FOREIGN KEY (record_id) REFERENCES records(id) ON DELETE CASCADE
);
