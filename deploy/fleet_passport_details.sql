ALTER TABLE fleet_driver_documents
    ADD COLUMN passport_number VARCHAR(50) NOT NULL DEFAULT '',
    ADD COLUMN passport_issuer VARCHAR(400) NOT NULL DEFAULT '',
    ADD COLUMN passport_issued DATE NULL,
    ADD COLUMN birth_date DATE NULL;
