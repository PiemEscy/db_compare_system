DROP DATABASE IF EXISTS test_db_from;
DROP DATABASE IF EXISTS test_db_to;

CREATE DATABASE IF NOT EXISTS test_db_from;
CREATE DATABASE IF NOT EXISTS test_db_to;

-- ==========================
-- FROM DATABASE
-- ==========================
USE test_db_from;

-- Common table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50),
    email VARCHAR(100) UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Table only in FROM
CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(10,2),
    status ENUM('pending','paid','cancelled') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Table with slight differences
CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email(email)
);

-- Table with composite primary key and extra constraints
CREATE TABLE invoices (
    invoice_id INT,
    customer_id INT,
    amount DECIMAL(12,2),
    issued_at DATE,
    PRIMARY KEY (invoice_id, customer_id),
    CONSTRAINT fk_invoices_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
);

-- ==========================
-- TO DATABASE
-- ==========================
USE test_db_to;

-- Common table (same as FROM)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50),
    email VARCHAR(100) UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Table only in TO
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100),
    price DECIMAL(10,2),
    stock INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Table with differences
CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL, -- different length
    email VARCHAR(150) UNIQUE,
    phone VARCHAR(20) NULL, -- extra column
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email(email)
);

-- Table with composite primary key but missing foreign key
CREATE TABLE invoices (
    invoice_id INT,
    customer_id INT,
    amount DECIMAL(12,2),
    issued_at DATE,
    PRIMARY KEY (invoice_id, customer_id)
    -- FK missing
);
