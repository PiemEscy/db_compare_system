DROP DATABASE IF EXISTS test_db_from;
DROP DATABASE IF EXISTS test_db_to;

CREATE DATABASE test_db_from;
CREATE DATABASE test_db_to;

-- ==========================
-- FROM DATABASE
-- ==========================
USE test_db_from;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL
);

CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL
);

CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_no VARCHAR(30) NOT NULL UNIQUE
);

CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    method VARCHAR(30) NOT NULL
);

-- 🔥 TABLE TO COMPARE (multiple FKs)
CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,

    user_id INT NOT NULL,
    customer_id INT NOT NULL,
    order_id INT NULL,
    payment_id INT NULL,

    amount DECIMAL(12,2) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    -- FK 1: SAME in both DBs
    CONSTRAINT fk_transactions_user
        FOREIGN KEY (user_id) REFERENCES users(id),

    -- FK 2: EXISTS ONLY IN FROM (missing in TO)
    CONSTRAINT fk_transactions_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id),

    -- FK 3: EXISTS IN BOTH but will be DIFFERENT reference in TO
    CONSTRAINT fk_transactions_order
        FOREIGN KEY (order_id) REFERENCES orders(id),

    -- FK 4: EXISTS IN BOTH but constraint NAME will differ in TO
    CONSTRAINT fk_transactions_payment
        FOREIGN KEY (payment_id) REFERENCES payments(id)
);


-- ==========================
-- TO DATABASE
-- ==========================
USE test_db_to;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL
);

CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL
);

CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_no VARCHAR(30) NOT NULL UNIQUE
);

-- Extra table only in TO (for FK mismatch test)
CREATE TABLE archived_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_no VARCHAR(30) NOT NULL UNIQUE
);

CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    method VARCHAR(30) NOT NULL
);

-- 🔥 SAME TABLE NAME but FK differences
CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,

    user_id INT NOT NULL,
    customer_id INT NOT NULL,
    order_id INT NULL,
    payment_id INT NULL,

    amount DECIMAL(12,2) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    -- FK 1: SAME in both DBs
    CONSTRAINT fk_transactions_user
        FOREIGN KEY (user_id) REFERENCES users(id),

    -- FK 2: MISSING in TO on purpose (customer FK not included)

    -- FK 3: DIFFERENT reference (orders -> archived_orders)
    CONSTRAINT fk_transactions_order
        FOREIGN KEY (order_id) REFERENCES archived_orders(id),

    -- FK 4: SAME relationship but DIFFERENT constraint NAME
    CONSTRAINT fk_tx_payment
        FOREIGN KEY (payment_id) REFERENCES payments(id)
);
