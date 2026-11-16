-- =====================================================================
-- DATABASE 1 of 2:  agrimart_admin
-- Used by the farmer / buyer / admin site (folder: agrimart_admin)
--
-- 1. In phpMyAdmin, create a database named  agrimart_admin  and click it.
-- 2. Import tab > choose this file > Go.
-- 3. Open http://localhost/agrimart_admin/setup.php once to create the admin login.
--
-- Every table and column has a COMMENT. In phpMyAdmin, open a table >
-- Structure tab and read the "Comments" column.
--
-- THE 7 TABLES
--   admins       -> the admin login
--   sequences    -> a counter that makes ids like f-1001, p-1002, SF-1003
--   member_ids   -> the 20 valid seller Member IDs (ABF-2026-001 ... 020)
--   farmers      -> registered sellers and whether the admin approved them
--   products     -> produce the farmers list for sale
--   orders       -> orders for farmers to handle (from the Buyer tab and from the customer site)
--   order_items  -> the products inside each order
--
-- RELATIONSHIPS (one-to-many unless stated)
--   member_ids 1---1 farmers        one Member ID is used by one farmer
--   farmers    1---* products       a farmer lists many products
--   farmers    1---* orders         a farmer receives many orders
--   orders     1---* order_items    an order contains many items
--   products   1---* order_items    a product can appear in many orders
--   admins and sequences stand alone (no relationships)
-- =====================================================================


CREATE TABLE admins (
  id            INT AUTO_INCREMENT PRIMARY KEY COMMENT 'Admin number, given automatically',
  username      VARCHAR(50)  NOT NULL UNIQUE   COMMENT 'Name the admin types to log in',
  password_hash VARCHAR(255) NOT NULL          COMMENT 'Password saved as an unreadable hash, never as plain text',
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the admin account was created'
) ENGINE=InnoDB COMMENT='Admin accounts that can log in to the Admin tab';

CREATE TABLE sequences (
  name  VARCHAR(20) PRIMARY KEY COMMENT 'Name of the counter (only one row: uid)',
  value BIGINT NOT NULL         COMMENT 'Last number used. Goes up by 1 for every new farmer, product or order id (f-1001, p-1002, SF-1003)'
) ENGINE=InnoDB COMMENT='Helper counter that makes unique ids. Not for users, leave it alone';

-- STARTING DATA: one row only. The counter starts at 1000, so the first
-- farmer/product/order created gets 1001 (f-1001, p-1002, SF-1003, ...).
INSERT INTO sequences (name, value)
VALUES ('uid', 1000);

CREATE TABLE farmers (
  id          VARCHAR(20)  PRIMARY KEY COMMENT 'Farmer id, for example f-1001',
  name        VARCHAR(120) NOT NULL    COMMENT 'Full name of the farmer',
  contact     VARCHAR(30)  NOT NULL    COMMENT 'Phone number',
  description VARCHAR(255) NULL        COMMENT 'Short note about the farm or what they grow (optional)',
  member_id   VARCHAR(20)  NOT NULL UNIQUE COMMENT 'Member ID used to register. Links to member_ids',
  status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' COMMENT 'pending = waiting for admin, approved = can sell, rejected = denied',
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the farmer registered',
  INDEX idx_farmers_status (status)
) ENGINE=InnoDB COMMENT='Sellers who registered. The admin approves or rejects them';

CREATE TABLE member_ids (
  member_id VARCHAR(20) PRIMARY KEY COMMENT 'Valid seller Member ID, ABF-2026-001 to ABF-2026-020',
  farmer_id VARCHAR(20) NULL        COMMENT 'Farmer who used this ID. Empty (NULL) = still available',
  CONSTRAINT fk_mid_farmer FOREIGN KEY (farmer_id) REFERENCES farmers(id) ON DELETE SET NULL
) ENGINE=InnoDB COMMENT='List of allowed Member IDs. A seller must enter one of these to register';

-- STARTING DATA: the 20 Member IDs that are allowed to register as sellers.
-- Only member_id is filled in. farmer_id is left empty (NULL) on purpose,
-- which means "this ID is still available". It is filled in automatically
-- when a farmer registers with that ID.
-- To add or change real IDs, edit this list (one ID per line).
INSERT INTO member_ids (member_id) VALUES
  ('ABF-2026-001'),
  ('ABF-2026-002'),
  ('ABF-2026-003'),
  ('ABF-2026-004'),
  ('ABF-2026-005'),
  ('ABF-2026-006'),
  ('ABF-2026-007'),
  ('ABF-2026-008'),
  ('ABF-2026-009'),
  ('ABF-2026-010'),
  ('ABF-2026-011'),
  ('ABF-2026-012'),
  ('ABF-2026-013'),
  ('ABF-2026-014'),
  ('ABF-2026-015'),
  ('ABF-2026-016'),
  ('ABF-2026-017'),
  ('ABF-2026-018'),
  ('ABF-2026-019'),
  ('ABF-2026-020');

ALTER TABLE farmers
  ADD CONSTRAINT fk_farmer_member FOREIGN KEY (member_id) REFERENCES member_ids(member_id);

CREATE TABLE products (
  id           VARCHAR(20)  PRIMARY KEY COMMENT 'Product id, for example p-1002',
  farmer_id    VARCHAR(20)  NOT NULL    COMMENT 'Farmer who sells this product. Links to farmers',
  name         VARCHAR(120) NOT NULL    COMMENT 'Product name, for example Kamatis',
  category     ENUM('Vegetables','Fruits','Rice & Grains','Fish & Seafood','Poultry & Eggs') NOT NULL COMMENT 'Product group',
  price        DECIMAL(10,2) NOT NULL   COMMENT 'Price per unit in pesos',
  qty          INT          NOT NULL DEFAULT 0 COMMENT 'Stock left to sell (how many units are available). Goes down when ordered',
  unit         ENUM('kilo','piece','dozen','sack','bundle') NOT NULL COMMENT 'What the price and stock are counted in',
  weather_tag  ENUM('Sunny/Dry Season','Rainy Season','All-Weather') NOT NULL DEFAULT 'All-Weather' COMMENT 'Season when the product is best harvested or sold',
  harvest_date DATE         NOT NULL    COMMENT 'Date it was harvested',
  icon         VARCHAR(10)  NULL        COMMENT 'Emoji shown when there is no photo',
  photo        MEDIUMTEXT   NULL        COMMENT 'Uploaded picture saved as text (base64). Empty if no photo',
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the product was listed',
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last time it was edited',
  CONSTRAINT fk_prod_farmer FOREIGN KEY (farmer_id) REFERENCES farmers(id) ON DELETE CASCADE,
  CONSTRAINT chk_prod_price CHECK (price > 0),
  CONSTRAINT chk_prod_qty   CHECK (qty >= 0),
  INDEX idx_prod_category (category),
  INDEX idx_prod_farmer (farmer_id)
) ENGINE=InnoDB COMMENT='Produce that farmers list for sale. Deleting a farmer deletes their products';

CREATE TABLE orders (
  id           VARCHAR(20)  PRIMARY KEY COMMENT 'Order id, for example SF-1003',
  buyer_name   VARCHAR(120) NOT NULL    COMMENT 'Name of the buyer',
  farmer_id    VARCHAR(20)  NOT NULL    COMMENT 'Farmer who must approve and prepare this order. Links to farmers',
  total        DECIMAL(10,2) NOT NULL   COMMENT 'Total price of the items in pesos (delivery fee is separate)',
  delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Delivery fee in pesos. Only used by orders that come from the customer site. 0 for pickup orders',
  status       ENUM('Pending','Approved','Rejected','Ready for Pickup','Completed') NOT NULL DEFAULT 'Pending' COMMENT 'Order progress: Pending > Approved > Ready for Pickup > Completed (or Rejected)',
  mode         ENUM('Pickup','Delivery') NOT NULL DEFAULT 'Pickup' COMMENT 'Pickup = placed in the Buyer tab. Delivery = placed on the customer site',
  pickup_date  DATE         NULL        COMMENT 'Pickup date (only for Pickup orders)',
  pickup_time  TIME         NULL        COMMENT 'Pickup time (only for Pickup orders)',
  phone        VARCHAR(30)  NOT NULL    COMMENT 'Buyer phone number',
  address      VARCHAR(255) NULL        COMMENT 'Delivery address (only for Delivery orders)',
  pay_method   VARCHAR(40)  NULL        COMMENT 'How the buyer pays: Cash on Pickup (Buyer tab), or COD / GCash (customer site)',
  farmer_note  VARCHAR(255) NULL        COMMENT 'Message from the farmer, for example the reason for rejecting',
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the order was placed',
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last time the status changed',
  CONSTRAINT fk_order_farmer FOREIGN KEY (farmer_id) REFERENCES farmers(id),
  INDEX idx_orders_buyer (buyer_name),
  INDEX idx_orders_farmer_status (farmer_id, status)
) ENGINE=InnoDB COMMENT='Orders the farmers must handle. They come from the Buyer tab (pickup) and from the customer site (delivery). One order per farmer';

CREATE TABLE order_items (
  id         INT AUTO_INCREMENT PRIMARY KEY COMMENT 'Row number, given automatically',
  order_id   VARCHAR(20)  NOT NULL    COMMENT 'Order this item belongs to. Links to orders',
  product_id VARCHAR(20)  NULL        COMMENT 'Product that was bought. Links to products. Empty if the product was deleted later',
  name       VARCHAR(120) NOT NULL    COMMENT 'Product name at the time of ordering (copied so old orders never change)',
  qty        INT          NOT NULL    COMMENT 'How many units were ordered',
  price_each DECIMAL(10,2) NOT NULL   COMMENT 'Price of one unit at the time of ordering (copied)',
  CONSTRAINT fk_item_order   FOREIGN KEY (order_id)   REFERENCES orders(id)   ON DELETE CASCADE,
  CONSTRAINT fk_item_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
  CONSTRAINT chk_item_qty CHECK (qty >= 0)
) ENGINE=InnoDB COMMENT='The products inside each order (one row per product)';
