CREATE TABLE IF NOT EXISTS client_account_manager_links (
    client_id BIGINT NOT NULL,
    position INT NOT NULL,
    account_id BIGINT NULL,
    legacy_manager_id BIGINT NULL,
    fallback_label VARCHAR(160) NOT NULL,
    PRIMARY KEY (client_id, position),
    UNIQUE (client_id, account_id),
    UNIQUE (client_id, legacy_manager_id),
    FOREIGN KEY (client_id) REFERENCES catalog_entries(id),
    FOREIGN KEY (account_id) REFERENCES users(id),
    CHECK ((account_id IS NULL) <> (legacy_manager_id IS NULL))
);
