-- Honey's Dressed Chicken and Frozen Food: inventory database
-- Built from the PHP code (db.php connects to "honeys_inventory" as root, no password).
-- Import in phpMyAdmin (Import tab) or:  mysql -u root < honeys_inventory.sql
-- Note: login is NOT stored in the database (login.php uses a fixed admin hash), so there is no users table.

CREATE DATABASE IF NOT EXISTS honeys_inventory
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE honeys_inventory;

-- Products (products.php, save_products.php, edit_product.php)
CREATE TABLE IF NOT EXISTS products (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_name    VARCHAR(100)  NOT NULL,
  category        VARCHAR(100)  NOT NULL DEFAULT '',
  quantity        INT           NOT NULL DEFAULT 0,
  unit            VARCHAR(30)   NOT NULL DEFAULT 'pcs',
  price           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  low_stock_level INT           NOT NULL DEFAULT 5,
  PRIMARY KEY (id),
  KEY idx_products_name (product_name),
  KEY idx_products_category (category),
  CONSTRAINT chk_products_qty   CHECK (quantity >= 0),
  CONSTRAINT chk_products_price CHECK (price >= 0),
  CONSTRAINT chk_products_low   CHECK (low_stock_level >= 0)
) ENGINE=InnoDB;

-- One row per receipt (sales.php, history.php). sale_date is read by dashboard/reports/export.
CREATE TABLE IF NOT EXISTS sales (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sale_date    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY idx_sales_date (sale_date)
) ENGINE=InnoDB;

-- Line items on a receipt. product_name/price are copied so receipts stay correct if a product is edited.
-- RESTRICT: a product that appears on a receipt cannot be deleted (edit_product.php checks this too).
CREATE TABLE IF NOT EXISTS sale_items (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sale_id      INT UNSIGNED NOT NULL,
  product_id   INT UNSIGNED NOT NULL,
  product_name VARCHAR(100)  NOT NULL,
  quantity     INT           NOT NULL,
  price        DECIMAL(10,2) NOT NULL,
  subtotal     DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_items_sale (sale_id),
  KEY idx_items_product (product_id),
  CONSTRAINT fk_items_sale    FOREIGN KEY (sale_id)    REFERENCES sales(id)    ON DELETE CASCADE,
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Stock log (stock_in.php, sales.php, edit_product.php, movements.php).
-- transaction_type is 'STOCK IN' or 'STOCK OUT'; created_at is the date column helpers.php looks for.
CREATE TABLE IF NOT EXISTS stock_transactions (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id       INT UNSIGNED NOT NULL,
  transaction_type VARCHAR(20)  NOT NULL,
  quantity         INT          NOT NULL,
  remarks          VARCHAR(255) NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tx_product (product_id),
  KEY idx_tx_created (created_at),
  CONSTRAINT fk_tx_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT chk_tx_type CHECK (transaction_type IN ('STOCK IN','STOCK OUT'))
) ENGINE=InnoDB;
