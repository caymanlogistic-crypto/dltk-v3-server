CREATE TABLE IF NOT EXISTS manager_profiles (
    entry_id BIGINT PRIMARY KEY,
    surname VARCHAR(160) NOT NULL DEFAULT '',
    patronymic VARCHAR(160) NOT NULL DEFAULT '',
    role_id BIGINT NOT NULL,
    phone VARCHAR(100) NOT NULL DEFAULT '',
    email VARCHAR(254) NOT NULL DEFAULT '',
    FOREIGN KEY (entry_id) REFERENCES catalog_entries(id),
    FOREIGN KEY (role_id) REFERENCES roles(id)
);

CREATE TABLE IF NOT EXISTS client_manager_links (
    client_id BIGINT NOT NULL,
    manager_id BIGINT NOT NULL,
    position INT NOT NULL,
    PRIMARY KEY (client_id, manager_id),
    UNIQUE (client_id, position),
    FOREIGN KEY (client_id) REFERENCES catalog_entries(id),
    FOREIGN KEY (manager_id) REFERENCES manager_profiles(entry_id)
);
