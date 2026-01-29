CREATE DATABASE IF NOT EXISTS db_compare_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE db_compare_system;


CREATE TABLE db_connections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    label VARCHAR(100) NULL,

    db_from_host VARCHAR(100) NOT NULL DEFAULT '127.0.0.1',
    db_from_port INT NOT NULL DEFAULT 3306,
    db_from_name VARCHAR(100) NOT NULL,
    db_from_user VARCHAR(100) NOT NULL,
    db_from_pass VARCHAR(255) NULL,

    db_to_host VARCHAR(100) NOT NULL DEFAULT '127.0.0.1',
    db_to_port INT NOT NULL DEFAULT 3306,
    db_to_name VARCHAR(100) NOT NULL,
    db_to_user VARCHAR(100) NOT NULL,
    db_to_pass VARCHAR(255) NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
